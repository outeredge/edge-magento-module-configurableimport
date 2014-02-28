<?php

class Edge_ConfigurableImport_AdminController extends Mage_Adminhtml_Controller_Action
{
    protected $_eavSetup;

    protected function _initAction()
    {
        $this->loadLayout()
            ->_title($this->__('outer/edge'))
            ->_title($this->__('Configurable Import'))
            ->_setActiveMenu('edge');

        return $this;
    }

    public function indexAction()
    {
        $this->_initAction()
            ->_addContent($this->getLayout()->createBlock('configurableimport/adminhtml_import'))
            ->renderLayout();
    }
    
    public function importAction()
    {
        $this->_eavSetup = new Mage_Eav_Model_Entity_Setup('core_setup');
        $this->_db = Mage::getSingleton('core/resource')->getConnection('core_read');
        
        if (isset($_FILES['csv']) && $_FILES['csv']['error'] == UPLOAD_ERR_OK && is_uploaded_file($_FILES['csv']['tmp_name'])){
            
            // Get the attribute ids and options
            $configurableAttributes = $this->_setupAttributes();
            
            $csv = fopen($_FILES['csv']['tmp_name'], 'r');
            $headers = fgetcsv($csv);

            $columns = array();
            foreach ($headers as $key=>$col){
                $columns[$col] = $key;
            }
            
            $defaults = $this->_getDefaults();
            
            $success = 0;
            $failed = 0;
            
            // Array for storing relationship data
            $configurables = array();
            
            $simpleSkuIncrement = 1;

            while (($row = fgetcsv($csv, 1000)) !== false) {
                
                $product = Mage::getModel('catalog/product');
                
                $isSimple = $row[$columns['sku']] === "" ? true : false;
                if ($isSimple){
                    // No SKU so probably a simple product
                    // Use the data from previous row
                    $product->setData($data);
                    $product->setTypeId('simple');
                } else {
                    $product->setData($defaults);
                }
                
                foreach ($columns as $attribute=>$key){
                    if (!$product->getData($attribute)){

                        // Add Option Values if they don't exist
                        if ($isSimple && array_key_exists($attribute, $configurableAttributes)){
                            if (!array_key_exists($row[$key], $configurableAttributes[$attribute]['options'])){
                                
                                $this->_eavSetup->addAttributeOption(array(
                                    'attribute_id' => $configurableAttributes[$attribute]['attribute_id'],
                                    'value' => array('new_option' => array($row[$key]))
                                ));
                                
                                $optionsUpdated = array();
                                $query = "SELECT * FROM `eav_attribute_option` `o` INNER JOIN `eav_attribute_option_value` `v` ON `o`.`option_id` = `v`.`option_id` WHERE `attribute_id` = " . $configurableAttributes[$attribute]['attribute_id'];
                                $results = $this->_db->query($query);
                                foreach ($results as $result){
                                    $optionsUpdated[$result['value']] = $result['option_id'];
                                }
                                $configurableAttributes[$attribute]['options'] = $optionsUpdated;
                            }
                            
                            $product->setData($attribute, $configurableAttributes[$attribute]['options'][$row[$key]]);

                        } else {
                            $product->setData($attribute, $row[$key]);
                        }
                    }
                }
                
                try {
                    if (!$isSimple){
                        $configurables[$row[$columns['sku']]] = array();
                        $data = $product->getData();
                        $simpleSkuIncrement = 1;
                    } else {
                        $product->setSku($product->getSku() . '-' . $simpleSkuIncrement);
                        $simpleSkuIncrement++;
                    }
                    
                    $product->save();
                    
                    if ($isSimple){
                        $configurables[$data['sku']][] = $product->getId();
                    }
                    
                    $success++;
                } catch (Exception $e) {
                    Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
                    $failed++;
                }
            }
            
            if ($success > 0){
                Mage::getSingleton('adminhtml/session')->addSuccess($success . ' products created.');
            }
            if ($failed > 0){
                Mage::getSingleton('adminhtml/session')->addError($failed . ' products failed.');
            }
        }
        
        $this->associateConfigurablesToSimples($configurables, $configurableAttributes['ids']);
        
        $this->_redirect('*/*/');
        return;
    }
    
    public function associateConfigurablesToSimples($configurables, $configurableAttributes)
    {
        foreach ($configurables as $sku => $simples){
            $configurable = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);
            
            $configurable->setCanSaveConfigurableAttributes(true);
            
            $configurable->getTypeInstance()->setUsedProductAttributeIds($configurableAttributes);
            
            $attributes = $configurable->getTypeInstance()->getConfigurableAttributesAsArray();
            foreach($attributes as $key => $value) {
                $attributes[$key]['label'] = $value['frontend_label'];
            }
            
            $configurable->setConfigurableAttributesData($attributes);
            $configurable->setCanSaveConfigurableAttributes(true);
            $configurable->setCanSaveCustomOptions(true);
            
            $associated = array();
            foreach ($simples as $simple){
                parse_str("position=", $associated[$simple]);
            }
            $configurable->setConfigurableProductsData($associated, $configurable);
            
            $configurable->save();
        }
    }
    
    protected function _setupAttributes()
    {
        $configurableAttributes = array('ids' => array());
        
        $attributes = $this->getRequest()->getParam('attribute');
        foreach ($attributes as $code){
            
            $options = array();
            
            // Load the attribute
            $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $code);
            if (!$attribute->getId()){

                $this->_eavSetup->addAttribute('catalog_product', $code, array(
                    'group'             => 'Configurable Data',
                    'type'              => 'int',
                    'backend'           => '',
                    'frontend'          => '',
                    'label'             => ucfirst($code),
                    'input'             => 'select',
                    'class'             => '',
                    'global'            => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
                    'configurable'      => true,
                    'visible'           => true,
                    'required'          => false,
                    'user_defined'      => true,
                    'visible_on_front'  => true
                ));
                
                $eavAttribute = new Mage_Eav_Model_Mysql4_Entity_Attribute();
                $configurableAttributes[$code] = array(
                    'attribute_id'  => $eavAttribute->getIdByCode('catalog_product', $code),
                    'options'       => $options
                );
                
                Mage::getSingleton('adminhtml/session')->addSuccess('Attribute "' . $code . '" has been created.');
            } 
            else {
                if ($attribute->usesSource()){
                    foreach ($attribute->getSource()->getAllOptions(false) as $option){
                        $options[$option['label']] = $option['value'];
                    }
                }
                $configurableAttributes[$code] = array(
                    'attribute_id'  => $attribute->getId(),
                    'options'       => $options
                );
            }

            $configurableAttributes['ids'][] = $configurableAttributes[$code]['attribute_id'];
        }
        
        return $configurableAttributes;
    }
    
    protected function _getDefaults()
    {
        $defaults = array(
            'attribute_set_id'  => 4,
            'type_id'           => 'configurable',
            'website_ids'       => array(1),
            'price'             => 0,
            'weight'            => 0,
            'visibility'        => Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
            'status'            => 1,
            'tax_class_id'      => 0,
            'stock_data'        => array(
                'is_in_stock'   => 1,
                'qty'           => 100
            )
        );
        
        return $defaults;
    }
}