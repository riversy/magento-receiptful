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
$installer = $this;
$installer->startSetup();

$installer
    ->getConnection()
    ->addColumn(
        $installer->getTable('sales/invoice'),
        'receiptful_receipt_failed_at',
        array(
            'type' => Varien_Db_Ddl_Table::TYPE_DATETIME,
            'nullable' => true,
            'default' => null,
            'comment' => 'Receiptful Receipt Sent Date'
        )
    );

$installer->endSetup();
