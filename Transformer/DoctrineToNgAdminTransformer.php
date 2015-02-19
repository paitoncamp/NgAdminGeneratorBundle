<?php

namespace marmelab\NgAdminGeneratorBundle\Transformer;

use Doctrine\Common\Util\Inflector;
use Doctrine\ORM\Mapping\ClassMetadata;

class DoctrineToNgAdminTransformer implements TransformerInterface
{
    /**
     * @see http://doctrine-dbal.readthedocs.org/en/latest/reference/types.html
     */
    private static $typeMapping = [
        'smallint' => 'number',
        'integer' => 'number',
        'bigint' => 'number',
        'decimal' => 'number',
        'float' => 'number',
        'string' => 'string',
        'guid' => 'string',
        'datetime' => 'string',
        'datetimez' => 'string',
        'time' => 'string',
        'text' => 'text',
        'boolean' => 'boolean',
        'date' => 'date',
    ];

    public function transform($doctrineMetadata)
    {
        $joinColumns = $this->getJoinColumns($doctrineMetadata);

        $transformedFields = [];
        foreach ($doctrineMetadata->fieldMappings as $fieldMapping) {
            $field = [
                'name' => $fieldMapping['fieldName'],
                'class' => $doctrineMetadata->name, // @TODO: move this data outside field mappings
            ];

            // if field is in relationship, we'll deal it later
            if (in_array($field['name'], array_keys($joinColumns))) {
                continue;
            }

            $field['type'] = self::$typeMapping[$fieldMapping['type']];
            $transformedFields[$field['name']] = $field;
        }

        // Deal with all relationships
        $transformedFields = array_merge($transformedFields, $joinColumns);

        // check for inversed relationships
        $inversedRelationships = $this->getInversedRelationships($doctrineMetadata);
        $transformedFields = array_merge($transformedFields, $inversedRelationships);

        return $transformedFields;
    }

    public function reverseTransform($ngAdminConfiguration)
    {
        throw new \DomainException("You shouldn't need to transform a ng-admin configuration into a Doctrine mapping.");
    }

    private function getJoinColumns($metadata)
    {
        $joinColumns = [];
        foreach ($metadata->associationMappings as $mappedEntity => $mapping) {
            // should own property, otherwise it's inversed relationship
            if (!$mapping['isOwningSide']) {
                continue;
            }

            // single relationship, through joinColumns
            if (isset($mapping['joinColumns'])) {
                $column = $mapping['joinColumns'][0];
                $joinColumns[$column['name']] = [
                    'type' => 'reference',
                    'name' => $column['name'],
                    'referencedEntity' => [
                        'name' => $mappedEntity,
                        'class' => $mapping['targetEntity'],
                    ],
                    'referencedField' => $column['referencedColumnName'],
                ];
            }

            // many-to-many relationship, through a joinTable
            if (isset($mapping['joinTable'])) {
                $joinColumns[$mapping['fieldName']] = [
                    'type' => 'reference_many',
                    'name' => $mapping['fieldName'],
                    'referencedEntity' => [
                        'name' => $this->getEntityName($mapping['targetEntity']),
                        'class' => $mapping['targetEntity'],
                    ],
                    'referencedField' => $mapping['joinTable']['inverseJoinColumns'][0]['referencedColumnName'],
                ];
            }
        }

        return $joinColumns;
    }

    private function getInversedRelationships($metadata)
    {
        $inversedRelationships = [];
        foreach ($metadata->associationMappings as $mappedEntity => $mapping) {
            // should own property, otherwise it's direct relationship
            if ($mapping['isOwningSide']) {
                continue;
            }

            $inversedRelationships[$mapping['fieldName']] = [
                'type' => 'referenced_list',
                'name' => $mappedEntity,
                'referencedEntity' => [
                    'name' => $this->getEntityName($mapping['targetEntity']),
                    'class' => $mapping['targetEntity'],
                ],
                'referencedField'=> $mapping['mappedBy'].'_id', // @TODO: find a more robust way
            ];
        }

        return $inversedRelationships;
    }

    private function getEntityName($className)
    {
        $classParts = explode('\\', $className);
        $entityName = end($classParts);

        return Inflector::tableize($entityName);
    }
}
