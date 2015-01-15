<?php
class Receiptful_Core_Adminhtml_ReceiptController extends Mage_Adminhtml_Controller_Action
{
    public function resendAction()
    {
        $_this = $this;
        $session = $this->_getSession();

        $invoiceId = $this->getRequest()->getParam('invoice_id');

        $redirect = function () use ($_this, $invoiceId) {
            $_this->_redirect('*/sales_invoice/view', array(
                'invoice_id'=> $invoiceId,
            ));
        };

        if (!$invoiceId) {
            $session->addError(Mage::helper('sales')->__('Invoice not found.'));

            return $redirect();
        }

        $invoice = Mage::getModel('sales/order_invoice')->load($invoiceId);

        if (!$invoice) {
            $session->addError(Mage::helper('sales')->__(sprintf('Invoice with id %s not found.', $invoiceId)));

            return $redirect();
        }

        $receiptId = $invoice->getReceiptfulId();

        if (!$receiptId) {
            $session->addError(Mage::helper('sales')->__('The receipt has not been sent with Receiptful.'));

            return $redirect();
        }

        try {
            Receiptful_Core_Model_Observer::sendRequest(array(), sprintf('/receipts/%s/send', $receiptId));

            $session->addSuccess(Mage::helper('sales')->__('The receipt has been sent correctly.'));
        } catch (Receiptful_Core_Exception_FailedRequestException $e) {
            $session->addError($e->getMessage());
        }

        return $redirect();
    }
}
