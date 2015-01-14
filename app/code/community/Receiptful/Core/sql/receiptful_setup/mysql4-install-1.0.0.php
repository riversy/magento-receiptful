<?php
$installer = $this;
$installer->startSetup();

$installer
    ->getConnection()
    ->addColumn(
        $installer->getTable('sales/invoice'),
        'receiptful_id',
        array(
            'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
            'nullable' => true,
            'default' => null,
            'comment' => 'Receiptful Id'
        )
    );

$installer->endSetup();
