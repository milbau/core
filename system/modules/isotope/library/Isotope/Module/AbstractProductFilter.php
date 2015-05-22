<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2014 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://isotopeecommerce.org
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Module;

use Isotope\Isotope;
use Isotope\Model\ProductType;

/**
 * AbstractProductFilter provides basic methods to handle product filtering
 *
 * @property array  $iso_searchFields
 * @property string $iso_searchAutocomplete
 * @property array  $iso_filterFields
 * @property bool   $iso_filterHideSingle
 * @property string $iso_newFilter
 * @property array  $iso_sortingFields
 * @property string $iso_listingSortField
 * @property string $iso_listingSortDirection
 * @property bool   $iso_enableLimit
 * @property string $iso_filterTpl
 */
abstract class AbstractProductFilter extends Module
{
    const FILTER_NEW = 'show_new';
    const FILTER_OLD = 'show_old';

    /**
     * Constructor.
     *
     * @param object $objModule
     * @param string $strColumn
     */
    public function __construct($objModule, $strColumn = 'main')
    {
        parent::__construct($objModule, $strColumn);

        \Controller::loadDataContainer('tl_iso_product');
        \System::loadLanguageFile('tl_iso_product');

        $this->iso_filterFields  = deserialize($this->iso_filterFields);
        $this->iso_sortingFields = deserialize($this->iso_sortingFields);
        $this->iso_searchFields  = deserialize($this->iso_searchFields);

        if (!is_array($this->iso_filterFields)) {
            $this->iso_filterFields = array();
        }

        if (!is_array($this->iso_sortingFields)) {
            $this->iso_sortingFields = array();
        }

        if (!is_array($this->iso_searchFields)) {
            $this->iso_searchFields = array();
        }
    }

    /**
     * Returns an array of attribute values found in the product table
     *
     * @param string $attribute
     * @param array  $categories
     * @param string $newFilter
     * @param string $sqlWhere
     *
     * @return array
     */
    protected function getUsedValuesForAttribute($attribute, array $categories, $newFilter = '', $sqlWhere = '')
    {
        $attributeTypes = $this->getProductTypeIdsByAttribute($attribute);
        $variantTypes   = $this->getProductTypeIdsByAttribute($attribute, true);

        if (empty($attributeTypes) && empty($variantTypes)) {
            return array();
        }

        $values         = array();
        $typeConditions = array();
        $join           = '';
        $categoryWhere  = '';
        $published      = '';
        $time           = \Date::floorToMinute();

        if ('' != $sqlWhere) {
            $sqlWhere = " AND " . $sqlWhere;
        }

        // Apply new/old product filter
        if ($newFilter == self::FILTER_NEW) {
            $sqlWhere .= " AND p1.dateAdded>=" . Isotope::getConfig()->getNewProductLimit();
        } elseif ($newFilter == self::FILTER_OLD) {
            $sqlWhere .= " AND p1.dateAdded<" . Isotope::getConfig()->getNewProductLimit();
        }

        if (BE_USER_LOGGED_IN !== true) {
            $published = "
                AND p1.published='1'
                AND (p1.start='' OR p1.start<'$time')
                AND (p1.stop='' OR p1.stop>'" . ($time + 60) . "')
            ";
        }

        if (!empty($attributeTypes)) {
            $typeConditions[] = "p1.type IN (" . implode(',', $attributeTypes) . ")";
        }

        if (!empty($variantTypes)) {
            $typeConditions[] = "p2.type IN (" . implode(',', $variantTypes) . ")";
            $join             = "LEFT OUTER JOIN tl_iso_product p2 ON p1.pid=p2.id";
            $categoryWhere    = "OR p1.pid IN (
                                    SELECT pid
                                    FROM tl_iso_product_category
                                    WHERE page_id IN (" . implode(',', $categories) . ")
                                )";

            if (BE_USER_LOGGED_IN !== true) {
                $published .= " AND (
                    p1.pid=0 OR (
                        p2.published='1'
                        AND (p2.start='' OR p2.start<'$time')
                        AND (p2.stop='' OR p2.stop>'" . ($time + 60) . "')
                    )
                )";
            }
        }

        $result = \Database::getInstance()->execute("
            SELECT DISTINCT p1.$attribute AS options
            FROM tl_iso_product p1
            $join
            WHERE
                p1.language=''
                AND p1.$attribute!=''
                " . $published . "
                AND (
                    p1.id IN (
                        SELECT pid
                        FROM tl_iso_product_category
                        WHERE page_id IN (" . implode(',', $categories) . ")
                    )
                    $categoryWhere
                )
                AND (
                    " . implode(' OR ', $typeConditions) . "
                )
                $sqlWhere
        ");

        while ($result->next()) {
            $values = array_merge($values, deserialize($result->options, true));
        }

        return $values;
    }

    /**
     * Get the sorting labels (asc/desc) for an attribute
     *
     * @param string
     *
     * @return array
     */
    protected function getSortingLabels($field)
    {
        $arrData = &$GLOBALS['TL_DCA']['tl_iso_product']['fields'][$field];

        switch ($arrData['eval']['rgxp']) {
            case 'price':
            case 'digit':
                return array($GLOBALS['TL_LANG']['MSC']['low_to_high'], $GLOBALS['TL_LANG']['MSC']['high_to_low']);

            case 'date':
            case 'time':
            case 'datim':
                return array($GLOBALS['TL_LANG']['MSC']['old_to_new'], $GLOBALS['TL_LANG']['MSC']['new_to_old']);
        }

        return array($GLOBALS['TL_LANG']['MSC']['a_to_z'], $GLOBALS['TL_LANG']['MSC']['z_to_a']);
    }

    /**
     * Get product type IDs with given attribute enabled
     *
     * @param string $attributeName
     * @param bool   $forVariants
     *
     * @return array
     */
    private function getProductTypeIdsByAttribute($attributeName, $forVariants = false)
    {
        static $cache;

        if (null === $cache) {
            /** @type ProductType[] $productTypes */
            $productTypes = ProductType::findAll();
            $cache        = array();

            if (null !== $productTypes) {
                foreach ($productTypes as $type) {
                    foreach ($type->attributes as $attribute => $config) {
                        if ($config['enabled']) {
                            $cache['attributes'][$attribute][] = $type->id;
                        }
                    }

                    if ($type->variants) {
                        foreach ($type->variant_attributes as $attribute => $config) {
                            if ($config['enabled']) {
                                $cache['variant_attributes'][$attribute][] = $type->id;
                            }
                        }
                    }
                }
            }

            foreach ($cache as $property => $attributes) {
                foreach ($attributes as $attribute => $values) {
                    $cache[$property][$attribute] = array_unique($values);
                }
            }
        }

        if ($forVariants) {
            return (array) $cache['variant_attributes'][$attributeName];
        } else {
            return (array) $cache['attributes'][$attributeName];
        }
    }
}
