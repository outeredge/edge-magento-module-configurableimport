<?php

class Edge_ConfigurableImport_Block_Adminhtml_Import extends Mage_Adminhtml_Block_Template
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('configurableimport/import.phtml');
    }
}
