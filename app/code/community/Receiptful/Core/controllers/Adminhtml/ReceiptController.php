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

        return $redirect();
    }
}
