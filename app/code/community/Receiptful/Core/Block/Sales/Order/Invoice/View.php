<?php
class Receiptful_Core_Block_Sales_Order_Invoice_View extends Mage_Adminhtml_Block_Sales_Order_Invoice_View
{
    public function __construct()
    {
        parent::__construct();

        $this->_removeButton('send_notification');
    }
}
