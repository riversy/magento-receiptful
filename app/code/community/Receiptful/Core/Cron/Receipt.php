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
class Receiptful_Core_Cron_Receipt
{
    /**
     * Resend receipts marked as failed.
     */
    public function resendFailures()
    {
        $resource = Mage::getSingleton('core/resource');

        $readConnection = $resource->getConnection('core_read');

        $query = 'SELECT * FROM ' . $resource->getTableName('sales/invoice') .
            ' WHERE receiptful_receipt_sent_at IS NULL' .
            ' AND receiptful_id is NOT NULL';

        $results = $readConnection->fetchAll($query);

        foreach ($results as $invoiceData) {
            Mage::Log(sprintf('Running send on ' . $invoiceData['entity_id']));

            $invoice = Mage::getModel('sales/order_invoice')->load($invoiceData['entity_id']);

            try {
                Receiptful_Core_Observer_Receipt::createInvoiceReceipt($invoice);
            } catch (Exception $e) {
                Mage::Log($e);
            }
        }
    }
}
