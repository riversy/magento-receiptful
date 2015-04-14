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

class Receiptful_Core_Block_Tracking extends Mage_Core_Block_Template
{
    protected $_order;

    /**
     * If Order was placed
     *
     * @return bool
     */
    public function hasOrder()
    {
        return !!$this->getOrder();
    }

    /**
     * Placed Order Instance
     *
     * @return mixed
     */
    public function getOrder()
    {
        if (!$this->_order) {

            $orderId = Mage::getSingleton('checkout/session')->getLastOrderId();
            if ($orderId){
                $order = Mage::getModel('sales/order')->load($orderId);
            }

            $this->_order = $order;
        }

        return $this->_order;
    }
}