<?php

namespace Drupal\external_entities_carto\Plugin\ExternalEntities\StorageClient;

use Drupal\external_entities\Plugin\ExternalEntities\StorageClient\Rest;

/**
 * CARTO implementation of an external entity storage client.
 *
 * @ExternalEntityStorageClient(
 *   id = "carto_client",
 *   label = "CARTO"
 * )
 */
class CartoClient extends Rest {

  /**
   * {@inheritdoc}
   */
  public function delete(\Drupal\external_entities\ExternalEntityInterface $entity) {
    $query = 'DELETE FROM ' . $this->configuration['endpoint'] . ' WHERE cartodb_id = ' . $entity->externalId();
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    $query = 'SELECT * FROM ' . $this->configuration['endpoint'] . ' WHERE cartodb_id = ' . $id;
    $response = $this->cartoExecuteQuery($query);
    return (object) $this->responseDecoderFactory->getDecoder($this->configuration['format'])->decode($response->getBody())['rows'][0];
  }

  /**
   * {@inheritdoc}
   */
  public function save(\Drupal\external_entities\ExternalEntityInterface $entity) {
    if ($entity->externalId()) {
      $fields = [];
      foreach ($entity->getMappedObject() as $key => $value) {
        if ($key == 'the_geom') {
          $fields[] = "the_geom = ST_GeomFromText('$value', 4326)";
        }
        elseif (is_numeric($value)) {
          $fields[] = "$key = $value";
        }
        else {
          $fields[] = "$key = '$value'";
        }
      }

      $query = 'UPDATE ' . $this->configuration['endpoint'] . ' SET ' . implode(', ', $fields) . 'WHERE cartodb_id = ' . $entity->externalId();
      $this->cartoExecuteQuery($query);

      $object = $this->load($entity->externalId());
      $result = SAVED_UPDATED;
    }
    else {
      $keys = $fields = [];
      foreach ($entity->getMappedObject() as $key => $value) {
        if ($key == 'cartodb_id') {
          continue;
        }
        $keys[] = $key;
        if ($key == 'the_geom') {
          $fields[] = "ST_GeomFromText('$value', 4326)";
        }
        elseif (is_numeric($value)) {
          $fields[] = "$value";
        }
        else {
          $fields[] = "'$value'";
        }
      }

      $query = 'INSERT INTO ' . $this->configuration['endpoint'] . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $fields) .')  RETURNING cartodb_id';
      $response = $this->cartoExecuteQuery($query);
      $id = $this->decoder->getDecoder($this->configuration['format'])->decode($response->getBody())['rows'][0]['cartodb_id'];
      $object = $this->load($id);
      $result = SAVED_NEW;
    }

    $entity->mapObject($object);
    return $result;
  }

  public function loadMultiple(array $ids = NULL) {
    $query = 'SELECT * FROM ' . $this->configuration['endpoint'] . ' WHERE cartodb_id IN (' . implode(',', $ids ) . ');';
    $response = $this->cartoExecuteQuery($query);
    $content = $response->getBody();
    $format = $this->configuration['response_format'];
    $content = $this->responseDecoderFactory->getDecoder($format)->decode($content);
    if (empty($content['rows'])) {
      return [];
    }
    $data = [];
    foreach ($content['rows'] as $row) {
      $data[$row['cartodb_id']] = $row;
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function query(array $parameters = [], array $sorts = [], $start = NULL, $length = NULL) {
    $query = 'SELECT * FROM ' . $this->configuration['endpoint'];
    if ($length) {
      $query .= ' LIMIT ' . $length;
    }
    if ($start) {
      $query .= ' OFFSET ' . $start;
    }

    $response = $this->cartoExecuteQuery($query);

    $results = $this->responseDecoderFactory->getDecoder($this->configuration['format'])
      ->decode($response->getBody())['rows'];
    foreach ($results as &$result) {
      $result = ((object) $result);
    }
    return $results;
  }

  /**
   * @param string $query
   *
   * @return \GuzzleHttp\Psr7\Response
   */
  protected function cartoExecuteQuery($query) {
//    dpm($query);
    $response = $this->httpClient->get(
      'https://' . $this->configuration['api_key']['key'] . '.carto.com/api/v2/sql',
      [
        'query' => [
          'q' => $query,
//          'api_key' => $this->configuration['parameters']['list']['api_key']
        ]
      ]
    );
    return $response;
  }

}
