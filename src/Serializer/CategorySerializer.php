<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Page\ProductCategory;

/**
 * Serialises a {@link ProductCategory} as a flat node (its children are referenced by id; the category resource
 * assembles the nested tree). Fires `updateSerialisedCategory`.
 */
class CategorySerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var ProductCategory $category */
        $category = $record;

        $data = [
            'id' => (int) $category->ID,
            'title' => (string) $category->Title,
            'menuTitle' => (string) $category->MenuTitle,
            'urlSegment' => (string) $category->URLSegment,
            'link' => (string) $category->Link(),
            'parentID' => (int) $category->ParentID,
            'childCategoryIDs' => array_map('intval', $category->ChildCategories()->column('ID')),
        ];

        $this->extend('updateSerialisedCategory', $data, $category);

        return $data;
    }
}
