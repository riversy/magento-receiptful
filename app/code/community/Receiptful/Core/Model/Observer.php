<?php
class Receiptful_Core_Model_Observer
{
    const RECEIPTFUL_API_KEY_CONFIGURATION = 'receiptful/configuration/api_key';

    const RECEIPTFUL_URL = 'http://localhost:9000/api/v1';

    public function createReceipt(Varien_Event_Observer $observer)
    {
        // This should override email sending but not persisting it
        Mage::app()->getStore()->setConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, '0');

        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();

        $data = $this->transformInvoiceToReceipt($invoice);

        try {
            $result = static::sendRequest($data, '/receipts');

            $this->handleUpsellResponse($result);

            $invoice->setReceiptfulId($result['_id']);

            $order->addStatusToHistory(
                $order->getStatus(),
                'Receiptful receipt sent correclty.',
                false
            );
        } catch (Receiptful_Core_Exception_FailedRequestException $e) {
            $order->addStatusToHistory(
                $order->getStatus(),
                'Receiptful failed to send receipt: ' . $e->getMessage(),
                false
            );
        }
    }

    private function handleUpsellResponse(array $response)
    {
        if (!isset($response['upsell'])) {
            return;
        }

        $upsell = $response['upsell'];

        if (!$upsell['active']) {
            return;
        }

        $handlers = array(
            'discountcoupon' => array($this, 'handleDiscountCoupon'),
            'shippingcoupon' => array($this, 'handleShippingCoupon')
        );

        if (!array_key_exists($upsell['upsellType'], $handlers)) {
            return;
        }

        // All customer group ids
        $customerGroupIds = Mage::getModel('customer/group')->getCollection()->getAllIds();

        // SalesRule Rule model
        $rule = Mage::getModel('salesrule/rule');

        $couponCode = $upsell['couponCode'];
        $description = 'Receiptful Coupon Code ' . $couponCode;
        $couponType = $upsell['couponType'];
        $upsellType = $upsell['upsellType'];

        $websiteIds = array_map(
            function ($website) {
                return $website->getId();
            },
            Mage::app()->getWebsites()
        );

        $rule->setName($description)
            ->setDescription($description)
            ->setCouponType(Mage_SalesRule_Model_Rule::COUPON_TYPE_SPECIFIC)
            ->setCouponCode($couponCode)
            ->setUsesPerCustomer(1)
            ->setUsesPerCoupon(1)
            ->setCustomerGroupIds($customerGroupIds)
            ->setIsActive(1)
            ->setStopRulesProcessing(0)
            ->setIsAdvanced(1)
            ->setSortOrder(0)
            ->setDiscountQty(1)
            ->setDiscountStep(0)
            ->setWebsiteIds($websiteIds)
            ->setToDate($upsell['expiresAt']);

        call_user_func($handlers[$upsell['upsellType']], $upsell, $rule);

        $rule
            ->save();
    }

    private function handleShippingCoupon(array $upsell, $rule)
    {
        $rule
            ->setSimpleAction(Mage_SalesRule_Model_Rule::BY_FIXED_ACTION)
            ->setDiscountAmount(0)
            ->setSimpleFreeShipping(Mage_SalesRule_Model_Rule::FREE_SHIPPING_ITEM);

    }

    private function handleDiscountCoupon(array $upsell, $rule)
    {
        $simpleAction = $upsell['couponType'] === 1 ?
            Mage_SalesRule_Model_Rule::BY_FIXED_ACTION :
            Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION;

        $rule
            ->setSimpleAction($simpleAction)
            ->setDiscountAmount($upsell['amount']);
    }

    private function transformInvoiceToReceipt(Mage_Sales_Model_Order_Invoice $invoice)
    {
        $order = $invoice->getOrder();

        $data = array(
            'reference' => $order->getIncrementId(),
            'currency' => $invoice->getOrderCurrencyCode(),
            'amount' => $invoice->getGrandTotal(),
            'to' => $order->getCustomerEmail(),
            'from' => Mage::getStoreConfig('trans_email/ident_general/email'),
            'items' => array(),
            'subtotals' => array(),
            'billing' => array()
        );

        if ($payment = $order->getPayment()) {
            $data['payment'] = array(
                'type' => $payment->getMethodInstance()->getTitle()
            );
        }

        foreach ($invoice->getAllItems() as $item) {
            $data['items'][] = array(
                'reference' => $item->getSku(),
                'description' => $item->getName(),
                'quantity' => (int) $item->getQty(),
                'amount' => $item->getPrice()
            );
        }

        if ($amount = $invoice->getDiscountAmount()) {
            $data['subtotals'][] = array(
                'description' => $invoice->getDiscountDescription(),
                'amount' => $amount * -1
            );
        }

        if ($amount = $invoice->getTaxAmount()) {
            $data['subtotals'][] = array(
                'description' => 'Tax', // @TODO i18n
                'amount' => $amount
            );
        }

        if ($amount = $invoice->getShippingAmount()) {
            $data['subtotals'][] = array(
                'description' => $order->getShippingDescription(),
                'amount' => $amount
            );
        }

        if ($shippingAddress = $order->getShippingAddress()) {
            $data['shipping'] = array(
                'firstName' => $shippingAddress->getFirstname(),
                'lastName' => $shippingAddress->getLastname(),
                'company' => $shippingAddress->getCompany(),
                'city' => $shippingAddress->getCity(),
                'state' => $shippingAddress->getRegion(),
                'postcode' => $shippingAddress->getPostcode(),
                'country' => $shippingAddress->getCountryId()
            );

            if (count($street = $shippingAddress->getStreet()) > 0) {
                $data['shipping']['addressLine1'] = $street[0];
            }

            if (count($street = $shippingAddress->getStreet()) > 1) {
                $data['shipping']['addressLine2'] = $street[1];
            }
        }

        if ($billingAddress = $order->getBillingAddress()) {
            $data['billing']['address'] = array(
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'company' => $billingAddress->getCompany(),
                'city' => $billingAddress->getCity(),
                'state' => $billingAddress->getRegion(),
                'postcode' => $billingAddress->getPostcode(),
                'country' => $billingAddress->getCountryId()
            );

            if (count($street = $billingAddress->getStreet()) > 0) {
                $data['billing']['address']['addressLine1'] = $street[0];
            }

            if (count($street = $billingAddress->getStreet()) > 1) {
                $data['billing']['address']['addressLine2'] = $street[1];
            }

            $data['billing']['phone'] = $billingAddress->getTelephone();
            $data['billing']['email'] = $billingAddress->getEmail();
        }

        return $data;
    }

    public static function sendRequest(array $data, $url)
    {
        $apiKey = Mage::getStoreConfig(self::RECEIPTFUL_API_KEY_CONFIGURATION);

        // If the module has not been configured yet, skip everything
        if (!$apiKey) {
            throw new Receiptful_Core_Exception_FailedRequestException('401: your api key seems not correct, please check it.');
        }

        $encodedData = json_encode($data);

        $ch = curl_init(self::RECEIPTFUL_URL . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($encodedData),
            'X-ApiKey: ' . $apiKey
        ));

        $result = curl_exec($ch);


        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if (in_array($httpCode, array(200, 201))) {
            return json_decode($result, true);
        }

        if (400 === $httpCode) {
            $result = json_decode($result, true);

            throw new Receiptful_Core_Exception_FailedRequestException($httpCode . ': ' . implode(', ', $result));
        }

        if (401 === $httpCode) {
            throw new Receiptful_Core_Exception_FailedRequestException($httpCode . ': your api key seems not correct, please check it.');
        }

        throw new Receiptful_Core_Exception_FailedRequestException($httpCode . ': an unexpected exception has occurred.');
    }

    public function addCustomResendButton($observer)
    {
        $block = $observer->getEvent()->getBlock();

        if (
            $block instanceof Mage_Adminhtml_Block_Sales_Order_Invoice_View &&
            'sales_order_invoice' === $block->getRequest()->getControllerName()
        ) {
            $block->removeButton('send_notification');

            $invoice = Mage::registry('current_invoice');

            $receiptId = $invoice->getReceiptfulId();

            // If we don't have a receiptful id, stick to default send functionality
            if (!$receiptId) {
                return;
            }

            $resendUrl = Mage::helper('adminhtml')
                ->getUrl(
                    'adminhtml/receipt/resend',
                    array(
                        'order_id'  => $invoice->getOrder()->getId(),
                        'invoice_id'=> $invoice->getId(),
                    )
                );

            $block->addButton('send_notification', array(
                'label'     => Mage::helper('sales')->__('Send Email'),
                'onclick'   => 'confirmSetLocation(\''
                . Mage::helper('sales')->__('Are you sure you want to send Receipt email to customer?')
                . '\', \'' . $resendUrl . '\')'
            ));
        }
    }

    public function addViewReceiptButton($observer)
    {
        $block = $observer->getEvent()->getBlock();

        if (
            $block instanceof Mage_Adminhtml_Block_Sales_Order_Invoice_View &&
            'sales_order_invoice' === $block->getRequest()->getControllerName()
        ) {
            $invoice = Mage::registry('current_invoice');

            $receiptId = $invoice->getReceiptfulId();

            if (!$receiptId) {
                return;
            }

            $receiptUrl = 'https://app.receiptful.com/receipt/' . $receiptId;

            $block->addButton('view_receipt', array(
                'label'     => Mage::helper('sales')->__('View Receipt'),
                'onclick'   => 'popWin(\''.$receiptUrl.'\', \'_blank\')'
            ));
        }
    }
}
