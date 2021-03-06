<?php
namespace Clusterpoint\Query;

use Clusterpoint\Client;
use Clusterpoint\Helper\Key;
use Clusterpoint\Helper\Raw;
use Clusterpoint\Query\Scope;
use Clusterpoint\Transport\Rest as DataLayer;
use Clusterpoint\Exceptions\ClusterpointException;
use Clusterpoint\Contracts\ConnectionInterface;
/**
 *
 * Parses Query Builder Requests.
 *
 * @category   Clusterpoint 4.0 PHP Client API
 * @package    clusterpoint/php-client-api-v4
 * @copyright  Copyright (c) 2016 Clusterpoint (http://www.clusterpoint.com)
 * @author     Marks Gerasimovs <marks.gerasimovs@clusterpoint.com>
 * @license    http://opensource.org/licenses/MIT    MIT
 */
class Parser
{
    /**
     * Pass back SELECT clause string to set in scope.
     *
     * @param  mixed  $select
     * @return string
     */
    public static function select($select)
    {
        if (gettype($select)=="array") {
            foreach ($select as $key => $field) {
                if ($field instanceof Key) {
                    $alias =  '"'.$field.'"';
                    $field =  self::field($field);
                    $select[$key] = "{$field} as {$alias}";
                }
            }
            $select = implode(", ", $select);
        } elseif (gettype($select)!="string") {
            throw new ClusterpointException("\"->select()\" function: passed parametr is not in valid format.", 9002);
        }
        return $select;
    }

    /**
     * Pass back WHERE clause string to append the scope.
     *
     * @param  string  $field
     * @param  string  $operator
     * @param  mixed   $value
     * @param  string  $logical
     * @return string
     */
    public static function where($field, $operator, $value, $logical)
    {
        if (gettype($field)=="array") {
            throw new ClusterpointException("\"->where()\" function: passed field selector is not in valid format.", 9002);
        }
        if ($operator===null) {
            return "{$logical} {$field} ";
        } elseif ($value===null) {
            $value = $operator;
            $operator = '==';
        }
        if ($field instanceof Key) {
            $field =  self::field("{$field}");
        }
        if (!($value instanceof Raw)) {
        	if (is_string($value)){
				$value = '"'.Client::escape($value).'"';
			}
			else {
				$value =  json_encode($value);
			}
        }
        return "{$logical} {$field}{$operator}{$value} ";
    }

    /**
     * Pass back LIMIT parametr to set to scope.
     *
     * @param  mixed  $limit
     * @return int
     */
    public static function limit($limit)
    {
        if (!is_numeric($limit)) {
            throw new ClusterpointException("\"->limit()\" function: passed parametr is not in valid format.", 9002);
        }
        return intval($limit);
    }

    /**
     * Pass back OFFSET parametr to set to scope.
     *
     * @param  mixed  $offset
     * @return int
     */
    public static function offset($offset)
    {
        if (!is_numeric($offset)) {
            throw new ClusterpointException("\"->offset()\" function: passed parametr is not in valid format.", 9002);
        }
        return intval($offset);
    }

    /**
     * Pass back ORDER BY Clause to append the scope.
     *
     * @param  mixed  $field
     * @param  string $order
     * @return string
     */
    public static function orderBy($field, $order)
    {
        if (!$order) {
            $order = 'DESC';
        }
        $order = strtoupper($order);
        if (!($order=='ASC' || $order=='DESC')) {
            throw new ClusterpointException("\"->order()\" function: ordering should be DESC or ASC.", 9002);
        }
        if (!(gettype($field)=="string" || $field instanceof Key || $field instanceof Raw)) {
            throw new ClusterpointException("\"->order()\" function: passed field selector is not in valid format.", 9002);
        }
        if ($field instanceof Key) {
            $field =  self::field("{$field}");
        }
        return "{$field} {$order}";
    }

    /**
     * Pass back GROUP BY Clause to append the scope.
     *
     * @param  mixed  $field
     * @return string
     */
    public static function groupBy($field)
    {
        if (!(gettype($field)=="string" || $field instanceof Key || $field instanceof Raw)) {
            throw new ClusterpointException("\"->group()\" function: passed field selector is not in valid format.", 9002);
        }
        if ($field instanceof Key) {
            $field =  self::field("{$field}");
        }
        return "{$field}";
    }

    /**
     * Set query parametrs to execute - retrieve by "_id".
     *
     * @param  string  $id
     * @param  \stdClass  $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function find($id = null, $connection)
    {
        if (gettype($id)!="string" && !is_numeric($id)) {
            throw new ClusterpointException("\"->find()\" function: \"_id\" is not in valid format.", 9002);
        }
        $connection->method = 'GET';
        $connection->action = '['.urlencode($id).']';
        $connection->multiple = false;
        return self::sendQuery($connection);
    }

    /**
     * Build query from scope. Passes to execute it.
     *
     * @param  \stdClass  $scope
     * @param  \stdClass $connection
     * @param  bool $multiple
     * @return \Clusterpoint\Response\Batch
     */
    public static function get(Scope $scope, $connection, $multiple, $return = false)
    {
        $from = $connection->db;
        if (strpos($from, '.') !== false) {
            $tmp = explode('.', $connection->db);
            $from = end($tmp);
        }

		if (!is_null($scope->listWordsField)) {
			if ($scope->listWordsField === '') {
				$from = 'LIST_WORDS(' . $from . ')';
			} else {
				$from = 'LIST_WORDS(' . $from . '.' . $scope->listWordsField . ')';
			}
		}

		if (!is_null($scope->alternativesField)) {
			if ($scope->alternativesField === '') {
				$from = 'ALTERNATIVES(' . $from . ')';
			} else {
				$from = 'ALTERNATIVES(' . $from . '.' . $scope->alternativesField . ')';
			}
		}

        $connection->query = $scope->prepend.'SELECT '.$scope->select.' FROM '.$from.' ';

		if (!is_null($scope->join)){
			$connection->query .= $scope->join.' ';
		}

        if ($scope->where!='') {
            $connection->query .= 'WHERE'.$scope->where;
        }
        if (count($scope->groupBy)) {
            $connection->query .= 'GROUP BY '.implode(", ", $scope->groupBy).' ';
        }
        if (count($scope->orderBy)) {
            $connection->query .= 'ORDER BY '.implode(", ", $scope->orderBy).' ';
        }
        $connection->query .= 'LIMIT '.$scope->offset.', '.$scope->limit;
        if ($return) {
            return $connection->query;
        }
        $connection->method = 'POST';
        $connection->action = '/_query';
        $connection->multiple = $multiple;
        $scope->resetSelf();
        return self::sendQuery($connection);
    }

    /**
     * Passes raw query string for exectuion.
     *
     * @param  string  $raw
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Batch
     */
    public static function raw($raw, $connection)
    {
        $connection->query = $raw;
        $connection->method = 'POST';
        $connection->action = '/_query';
        $connection->multiple = true;
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - delete by "_id".
     *
     * @param  string  $id
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function delete($id = null, $connection)
    {
        if (gettype($id)!="string" && !is_numeric($id)) {
            throw new ClusterpointException("\"->delete()\" function: \"_id\" is not in valid format.", 9002);
        }
        $connection->method = 'DELETE';
        $connection->action = '['.urlencode($id).']';
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - delete many by "_id".
     *
     * @param  array  $id
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function deleteMany(array $ids = array(), $connection)
    {
        if (!is_array($ids)) {
            throw new ClusterpointException("\"->deleteMany()\" function: \"_id\" is not in valid format.", 9002);
        }
        $connection->method = 'DELETE';
        $connection->action = '';

        // force strings! REST hates DELETE with integers for now...
        foreach ($ids as &$id) {
            $id = (string)$id;
        }
        $connection->query = json_encode($ids);
        return self::sendQuery($connection);
    }

    /**
     * Set query document to execute - Insert One.
     *
     * @param  array|object  $document
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function insertOne($document, $connection)
    {
        $connection->query = self::singleDocument($document);
        return self::insert($connection);
    }

    /**
     * Set query documents to execute - Insert Many.
     *
     * @param  array|object  $document
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function insertMany($document, $connection)
    {
        if (gettype($document)!="array" && gettype($document)!="object") {
            throw new ClusterpointException("\"->insert()\" function: parametr passed ".json_encode(self::escape_string($document))." is not in valid document format.", 9002);
        }
        if (gettype($document)=="object") {
            $document_array = array();
            foreach ($document as $value) {
                $document_array[] = $value;
            }
            $document = $document_array;
        }
        $connection->query = json_encode(array_values($document));
        $connection->multiple = true;
        return self::insert($connection);
    }

    /**
     * Set query parametrs to execute - Insert.
     *
     * @param  array|object  $document
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function insert($connection)
    {
        $connection->method = 'POST';
        $connection->action = '';
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - Update by "_id".
     *
     * @param  string  $id
     * @param  array|object  $document
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function update($id, $document, $connection)
    {
        $from = $connection->db;
        if (strpos($from, '.') !== false) {
            $tmp = explode('.', $connection->db);
            $from = end($tmp);
        }

        $connection->method = 'PATCH';
        $connection->action = '['.urlencode($id).']';
        switch (gettype($document)) {
            case "string":
                $connection->query = $document;
                break;
            case "array":
            case "object":
                $connection->method = 'POST';
                $connection->action = '/_query';
                $connection->query = 'UPDATE '.$from.'["'.$id.'"] SET '.self::updateRecursion($document);
                break;
            default:
                throw new ClusterpointException("\"->update()\" function: parametr passed ".json_encode(self::escape_string($document))." is not in valid format.", 9002);
                break;

        }
        return self::sendQuery($connection);
    }

    /**
     * Parse document for valid update command.
     *
     * @param  mixed  $document
     * @return string
     */
	private static function updateRecursion($document)
	{
		$result = array();
		foreach (self::toDotted($document, '', 1) as $path => $value) {
			$result[] = $path . $value;
		}

		return implode(' ', $result);
	}

	private static function toDotted($array, $prepend = '', $counter = 1)
	{
		$results = [];

		if ($prepend !== '') {
			$results['if (typeof ' . $prepend . ' === \'undefined\' || !(' . $prepend . ' instanceof Object) ) {' . $prepend . ' = {}}'] = ';';
		}

		foreach ($array as $key => $value) {
			if ($counter > 1) {
				$key = '["' . $key . '"]';
			} else {
				$results['if (typeof ' . $key . ' === \'undefined\' || !(' . $key . ' instanceof Object) ) {' . $key . ' = {}}'] = ';';
			}
			if (is_array($value) && !empty($value)) {
				// check if this is meant to be value-only array without assoc keys (P.S. assoc key = field name)
				if (is_array($value) && (count($value) === 0 || array_keys($value) === range(0, count($value) - 1))) {
					$results[$prepend . $key] = ' = ' . json_encode($value) . ';';
				} else {
					$results = array_merge($results, self::toDotted($value, $prepend . $key, $counter + 1));
				}
			} else {
				if (is_array($value) && count($value) === 0) {
					$results[$prepend . $key] = ' = ' . json_encode($value) . ';';
				} else {
					if (is_string($value)){
						$results[$prepend . $key] = ' = "' . Client::escape($value) . '";';
					}
					else {
						$results[$prepend . $key] = ' = ' . Client::escape($value) . ';';
					}

				}
			}
		}

		return $results;
	}

    /**
     * Set query parametrs to execute - Replace by "_id".
     *
     * @param  string  $id
     * @param  array|object  $document
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function replace($id, $document, $connection)
    {
        $connection->query = self::singleDocument($document);
        $connection->method = 'PUT';
        $connection->action = '['.urlencode($id).']';
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - Begin Transaction.
     *
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function beginTransaction($connection)
    {
        $connection->query = 'BEGIN_TRANSACTION';
        $connection->method = 'POST';
        $connection->action = '/_query';
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - Rollback Transaction.
     *
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function rollbackTransaction($connection)
    {
        $connection->query = 'ROLLBACK';
        $connection->method = 'POST';
        $connection->action = '/_query';
        return self::sendQuery($connection);
    }

    /**
     * Set query parametrs to execute - Commit Transaction.
     *
     * @param  \stdClass $connection
     * @return \Clusterpoint\Response\Single
     */
    public static function commitTransaction($connection)
    {
        $connection->query = 'COMMIT';
        $connection->method = 'POST';
        $connection->action = '/_query';
        return self::sendQuery($connection);
    }

    /**
     * Escapes invalid for regular usage key values.
     *
     * @param  string $field
     * @return field
     */
    public static function field($field)
    {
        return 'this["'.$field.'"]' ;
    }

    /**
     * Pass query Params to Transport Layer for execution.
     *
     * @param  \stdClass $connection
     * @return mixed
     */
    public static function sendQuery(ConnectionInterface $connection)
    {
        $response = DataLayer::execute($connection);
        $connection->resetSelf();
        return $response;
    }

	public static function getStatus($connection)
	{
		$connection->query = '';
		$connection->method = 'GET';
		$connection->action = '/_status';
		$connection->multiple = true;
		return self::sendQuery($connection);
	}

    /**
     * Encode single document in valid format.
     *
     * @param  mixed $document
     * @return string
     */
    public static function singleDocument($document)
    {
        if (gettype($document)!="array" && gettype($document)!="object") {
            throw new ClusterpointException("\"->insert()\" function: parametr passed ".json_encode(self::escape_string($document))." is not in valid document format.", 9002);
        }
        $query = "{";
        $first = true;
        foreach ($document as $key => $value) {
            if (!$first) {
                $query .= ",";
            }
            $query .= '"'.self::escape_string($key).'" : '.json_encode($value);
            $first = false;
        }
        $query .= '}';
        return $query;
    }

    /**
     * Escapes string for special characters.
     *
     * @param  string $string
     * @return string
     */
    public static function escape_string($string)
    {
        $search = array("\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a");
        $replace = array("\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z");
        return str_replace($search, $replace, $string);
    }
}
