<?php
class Receiptful_Core_Block_Sales_Order_Invoice_View extends Mage_Adminhtml_Block_Sales_Order_Invoice_View
{
    public function __construct()
    {
        parent::__construct();

        // Do nothing if emails are not active
        if (!$this->_isAllowedAction('emails')) {
            return;
        }

        $this->_removeButton('send_notification');

        $this->addButton('send_notification', array(
            'label'     => Mage::helper('sales')->__('Send Email'),
            'onclick'   => 'confirmSetLocation(\''
            . Mage::helper('sales')->__('Are you sure you want to send Receipt email to customer?')
            . '\', \'' . $this->getEmailUrl() . '\')'
        ));
    }
}
