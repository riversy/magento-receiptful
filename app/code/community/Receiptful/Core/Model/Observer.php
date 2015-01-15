<?php
class Receiptful_Core_Model_Observer
{
    const RECEIPTFUL_API_KEY_CONFIGURATION = 'receiptful/configuration/api_key';

    const RECEIPTFUL_URL = 'http://localhost:9000/api/v1';

    public function createReceipt(Varien_Event_Observer $observer)
    {
        $apiKey = Mage::getStoreConfig(self::RECEIPTFUL_API_KEY_CONFIGURATION);

        // If the module has not been configured yet, skip everything
        if (!$apiKey) {
            return;
        }

        // This should override email sending but not persisting it
        Mage::app()->getStore()->setConfig(Mage_Sales_Model_Order::XML_PATH_EMAIL_ENABLED, '0');

        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();

        $data = $this->transformInvoiceToReceipt($invoice);

        try {
            $result = $this->sendRequest($data, $apiKey);

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

    private function sendRequest(array $data, $apiKey)
    {
        $encodedData = json_encode($data);

        $ch = curl_init(self::RECEIPTFUL_URL . '/receipts');
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

        if (201 === $httpCode) {
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

            $block->addButton('send_notification', array(
                'label'     => Mage::helper('sales')->__('Send Email'),
                'onclick'   => 'confirmSetLocation(\''
                . Mage::helper('sales')->__('Are you sure you want to send Receipt email to customer?')
                . '\', \'' . 1 . '\')'
            ));
        }
    }
}
