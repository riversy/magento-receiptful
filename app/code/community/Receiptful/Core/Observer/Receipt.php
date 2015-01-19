<?php
/**
 * This file is part of the Receiptful extension.
 *
 * (c) Receiptful <info@receiptful.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Stefano Sala <stefano@receiptful.com>
 */
class Receiptful_Core_Observer_Receipt
{
    /**
     * Create a Receipt in Receiptful
     *
     * @param  Varien_Event_Observer $observer
     */
    public function createReceipt(Varien_Event_Observer $observer)
    {
        // This should override email sending but not persisting it
        Mage::app()->getStore()->setConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, '0');

        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();

        $data = $this->transformInvoiceToReceipt($invoice);

        try {
            $result = Receiptful_Core_ApiClient::sendRequest($data, '/receipts');

            $this->handleUpsellResponse($result);

            $invoice->setReceiptfulId($result['_id']);
            $invoice->setReceiptfulReceiptSentAt(time());
            $invoice->setEmailSent(true);

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

    /**
     * Add a custom resend button to Invoice view
     *
     * @param mixed $observer
     */
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

            $block->addButton('send_receiptful_notification', array(
                'label'     => Mage::helper('sales')->__('Send Email'),
                'onclick'   => 'confirmSetLocation(\''
                . Mage::helper('sales')->__('Are you sure you want to send Receipt email to customer?')
                . '\', \'' . $resendUrl . '\')'
            ));
        }
    }

    /**
     * Add a custom view receipt button to Invoice view
     *
     * @param mixed $observer
     */
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

            $receiptUrl = Receiptful_Core_ApiClient::getBaseUrl() . '/receipt/' . $receiptId;

            $block->addButton('view_receipt', array(
                'label'     => Mage::helper('sales')->__('View Receipt'),
                'onclick'   => 'popWin(\''.$receiptUrl.'\', \'_blank\')'
            ));
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

        $rule->save();
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

        if (($payment = $order->getPayment()) && $payment->getCcType()) {
            $data['payment'] = array(
                'type' => $payment->getCcType(),
                'last4' => $payment->getCcLast4()
            );
        }

        foreach ($invoice->getAllItems() as $item) {
            if ($item->getOrderItem()->getParentItem()) {
                continue;
            }

            $_item = array(
                'reference' => $item->getSku(),
                'description' => $item->getName(),
                'quantity' => (int) $item->getQty(),
                'amount' => $item->getPrice()
            );

            $options = $item->getOrderItem()->getProductOptions();

            if ($options && isset($options['attributes_info'])) {
                $attributes = $options['attributes_info'];

                $_item['metas'] = array();

                foreach ($attributes as $attribute) {
                    $_item['metas'][] = array(
                        'key' => $attribute['label'],
                        'value' => $attribute['value']
                    );
                }
            }

            $data['items'][] = $_item;
        }

        /**
         * Add shipping
         */
        if (!$invoice->getIsVirtual() && ((float) $invoice->getShippingAmount() || $invoice->getShippingDescription())) {
            $data['subtotals'][] = array(
                'description' => Mage::helper('sales')->__('Shipping & Handling'),
                'amount' => $invoice->getShippingAmount()
            );
        }

        /**
         * Add discount
         */
        if (((float)$invoice->getDiscountAmount()) != 0) {
            if ($invoice->getDiscountDescription()) {
                $discountLabel = Mage::helper('sales')->__('Discount (%s)', $invoice->getDiscountDescription());
            } else {
                $discountLabel = Mage::helper('sales')->__('Discount');
            }
            $data['subtotals'][] = array(
                'description' => $discountLabel,
                'amount' => $invoice->getDiscountAmount()
            );
        }

        /**
         * Add taxes
         */
        if ($amount = $invoice->getTaxAmount()) {
            $data['subtotals'][] = array(
                'description' => Mage::helper('sales')->__('Tax'),
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
}
