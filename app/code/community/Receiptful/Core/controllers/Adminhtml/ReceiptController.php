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
class Receiptful_Core_Adminhtml_ReceiptController extends Mage_Adminhtml_Controller_Action
{
    /**
     * Resend a receipt
     */
    public function resendAction()
    {
        $session = $this->_getSession();

        $invoiceId = $this->getRequest()->getParam('invoice_id');

        if (!$invoiceId) {
            $session->addError(Mage::helper('sales')->__('Invoice not found.'));

            return $this->redirect();
        }

        $invoice = Mage::getModel('sales/order_invoice')->load($invoiceId);

        if (!$invoice) {
            $session->addError(Mage::helper('sales')->__(sprintf('Invoice with id %s not found.', $invoiceId)));

            return $this->redirect();
        }

        $receiptId = $invoice->getReceiptfulId();

        if (!$receiptId) {
            $session->addError(Mage::helper('sales')->__('The receipt has not been sent with Receiptful.'));

            return $this->redirect();
        }

        try {
            Receiptful_Core_Model_Observer::sendRequest(array(), sprintf('/receipts/%s/send', $receiptId));

            $session->addSuccess(Mage::helper('sales')->__('The receipt has been sent correctly.'));
        } catch (Receiptful_Core_Exception_FailedRequestException $e) {
            $session->addError($e->getMessage());
        }

        return $this->redirect();
    }

    /**
     * Redirect to invoice view
     */
    private function redirect()
    {
        return $this->_redirect('*/sales_invoice/view', array(
            'invoice_id'=> $this->getRequest()->getParam('invoice_id'),
        ));
    }
}
