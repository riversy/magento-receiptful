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

        try {
            return $this->createInvoiceReceipt($observer->getEvent()->getInvoice());
        } catch (Receiptful_Core_Exception_FailedRequestException $e) {
            // Not raising this exception
        }
    }

    /**
     * Create a Receipt in Receiptful
     */
    public function createInvoiceReceipt($invoice)
    {
        $order = $invoice->getOrder();

        $data = $this->transformInvoiceToReceipt($invoice);

        if ($invoice->getReceiptfulId()) {
            return;
        }

        try {
            $result = Receiptful_Core_ApiClient::sendRequest($data, '/receipts');

            $this->handleUpsellResponse($result);

            $invoice->setReceiptfulId($result['_id']);
            $invoice->setEmailSent(true);

            $order->addStatusToHistory(
                $order->getStatus(),
                'Receiptful receipt sent correctly.',
                false
            );

        } catch (Receiptful_Core_Exception_FailedRequestException $e) {
            $invoice->setReceiptfulReceiptFailedAt(time());

            $order->addStatusToHistory(
                $order->getStatus(),
                'Receiptful failed to send receipt: ' . $e->getMessage(),
                false
            );

            throw $e;
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
                        'order_id' => $invoice->getOrder()->getId(),
                        'invoice_id' => $invoice->getId(),
                    )
                );

            $block->addButton('send_receiptful_notification', array(
                'label' => Mage::helper('sales')->__('Send Email'),
                'onclick' => 'confirmSetLocation(\''
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
                'label' => Mage::helper('sales')->__('View Receipt'),
                'onclick' => 'popWin(\'' . $receiptUrl . '\', \'_blank\')'
            ));
        }
    }

    private function handleUpsellResponse(array $response)
    {
        if (!isset($response['upsell'])) {
            return $this;
        }

        $upsell = $response['upsell'];

        /** @var Receiptful_Core_Helper_Coupon $couponHelper */
        $couponHelper = Mage::helper('receiptful/coupon');

        $rule = $couponHelper->getCouponRule($upsell);
        if (!$rule) {
            # If we didn't found the rule just create new one
            $rule = $couponHelper->createCouponRule($upsell);
        }

        $couponHelper->createCoupon($rule, $upsell);

        return $this;
    }

    /**
     * Validate Coupon Code for Expiration
     *
     * @param $observer
     * @return $this
     */
    public function validateCoupon($observer)
    {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $observer->getQuote();

        if ($quote && $quote->getCouponCode()){

            $couponCode = $quote->getCouponCode();

            /** @var Receiptful_Core_Helper_Coupon $couponHelper */
            $couponHelper = Mage::helper('receiptful/coupon');

            # Reset the coupon is it was expired
            if (!$couponHelper->isValid($couponCode)){
                $quote->setCouponCode(null);
            }
        }

        return $this;
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

        $items = $invoice->getAllItems();

        foreach ($items as $item) {
            if ($item->getOrderItem()->getParentItem()) {
                continue;
            }

            $_item = array(
                'reference' => $item->getSku(),
                'description' => $item->getName(),
                'quantity' => (int)$item->getQty(),
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

        // Similar products (same category)
        foreach ($items as $item) {
            if ($item->getOrderItem()->getParentItem()) {
                continue;
            }

            $product = $item->getOrderItem()->getProduct();

            $categoryIds = $product->getCategoryIds();

            if (0 === $categoryIds) {
                continue;
            }

            $category = Mage::getModel('catalog/category')->load($categoryIds[0]);

            $productCollection = Mage::getResourceModel('catalog/product_collection')
                ->addCategoryFilter($category)
                ->addFieldToFilter('entity_id', array('neq' => $product->getId()))
                ->addFieldToFilter('visibility', Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->setPageSize(2);

            $data['upsell'] = array(
                'products' => array()
            );

            foreach ($productCollection as $similarProduct) {

                $similarProduct = Mage::getModel('catalog/product')
                    ->load($similarProduct->getId());

                $similarProductData = array(
                    'title' => $similarProduct->getName(),
                    'description' => $similarProduct->getShortDescription(),
                    'actionUrl' => $similarProduct->getProductUrl()
                );

                try {
                    $imageHelper = Mage::helper('catalog/image');
                    $similarProductData['image'] = (string)$imageHelper->init($similarProduct, 'thumbnail');
                } catch (Exception $e) {
                    // Unable to load the image, skip it.
                }

                $data['upsell']['products'][] = $similarProductData;
            }

            break;
        }

        /**
         * Add shipping
         */
        if (!$invoice->getIsVirtual() && ((float)$invoice->getShippingAmount() || $invoice->getShippingDescription())) {
            $data['subtotals'][] = array(
                'description' => Mage::helper('sales')->__('Shipping & Handling'),
                'amount' => $invoice->getShippingAmount()
            );
        }

        /**
         * Add Share Me! Discount
         */
        if (Mage::helper('core')->isModuleEnabled('Magpleasure_Shareme')){

            if  ($amount = (float)$order->getSharemeDiscountAmount()){

                $data['subtotals'][] = array(
                    'description' => Mage::helper('shareme')->__('Share Me! Discount'),
                    'amount' => $amount,
                );
            }
        }

        /**
         * Add aheadWorks Points
         */
        if (Mage::helper('core')->isModuleEnabled('AW_Points')){

            if  ($amount = (float)$order->getMoneyForPoints()){

                $textForPoints = Mage::helper('points/config')->getPointUnitName();
                $title = Mage::helper('sales')->__('%s', $textForPoints);

                $data['subtotals'][] = array(
                    'description' => $title,
                    'amount' => -$amount,
                );
            }
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
