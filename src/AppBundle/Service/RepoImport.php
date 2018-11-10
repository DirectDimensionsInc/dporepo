<?php

namespace AppBundle\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use AppBundle\Controller\RepoStorageHybridController;
use AppBundle\Utils\AppUtilities;

use AppBundle\Controller\ItemsController;
use AppBundle\Controller\DatasetsController;
use AppBundle\Controller\ModelController;

class RepoImport implements RepoImportInterface {

  /**
   * @var object $u
   */
  public $u;

  /**
   * @var object $tokenStorage
   */
  public $tokenStorage;

  /**
   * @var object $itemsController
   */
  public $itemsController;

  /**
   * @var object $datasetsController
   */
  public $datasetsController;

  /**
   * @var object $modelsController
   */
  public $modelsController;

  /**
   * @var object $kernel
   */
  public $kernel;

  /**
   * @var string $project_directory
   */
  private $project_directory;

  /**
   * @var string $uploads_directory
   */
  private $uploads_directory;

  /**
   * @var string $external_file_storage_path
   */
  private $external_file_storage_path;

  /**
   * @var object $conn
   */
  private $conn;

  /**
   * @var object $repo_storage_controller
   */
  private $repo_storage_controller;

  /**
   * @var object $repoValidate
   */
  private $repoValidate;

  /**
   * @var object $image_extensions
   */
  private $image_extensions;

  /**
   * @var object $model_extensions
   */
  private $model_extensions;

  /**
   * @var object $default_image_file_name_map
   */
  private $default_image_file_name_map;

  /**
   * @var object $default_model_file_name_map
   */
  private $default_model_file_name_map;

  /**
   * Constructor
   * @param object  $kernel  Symfony's kernel object
   * @param string  $uploads_directory  Uploads directory path
   * @param string  $external_file_storage_path  External file storage path
   * @param string  $conn  The database connection
   * @param string  $uploads_directory  Uploads directory path
   */
  public function __construct(AppUtilities $u, TokenStorageInterface $tokenStorage, ItemsController $itemsController, DatasetsController $datasetsController, ModelController $modelsController, KernelInterface $kernel, string $uploads_directory, string $external_file_storage_path, \Doctrine\DBAL\Connection $conn)
  {
    $this->u = new AppUtilities();
    $this->tokenStorage = $tokenStorage;
    $this->itemsController = $itemsController;
    $this->datasetsController = $datasetsController;
    $this->modelsController = $modelsController;
    $this->kernel = $kernel;
    $this->project_directory = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR;
    $this->uploads_directory = (DIRECTORY_SEPARATOR === '\\') ? str_replace('\\', '/', $uploads_directory) : $uploads_directory;
    $this->external_file_storage_path = $external_file_storage_path;
    $this->conn = $conn;
    $this->repo_storage_controller = new RepoStorageHybridController($conn);
    $this->repoValidate = new RepoValidateData($conn);

    // Image extensions.
    $this->image_extensions = array(
      'tif',
      'tiff',
      'jpg',
      'jpeg',
      'cr2',
      'dng',
    );

    // Model extensions.
    $this->model_extensions = array(
      'obj',
      'ply',
      'gltf',
      'glb',
    );

    // Default image file name mapping.
    $this->default_image_file_name_map = array(
      'local_subject_id',
      'capture_dataset_field_id',
      'position_in_cluster_field_id',
      'cluster_position_field_id',
    );

    // Default model file name mapping.
    $this->default_model_file_name_map = array(
      'local_subject_id',
      'item_description',
      'model_purpose',
    );
  }

  /**
   * Import CSV
   *
   * @param array $params Parameters: job_id, project_id, parent_record_id, parent_record_type
   * @return array
   */
  public function import_csv($params = array())
  {

    $return = $csv_types = array();
    $skip_ingest = false;

    // If $params are empty, return an error.
    if(empty($params)) {
      $return['errors'][] = 'Parameters are empty';
    } else {
      // If $params values are empty, return errors.
      foreach ($params as $pkey => $pvalue) {
        if (empty($pvalue)) {
          $return['errors'][] = $pkey . ' is empty';
        }
      }
    }

    // If there are errors, return now.
    // TODO: insert errors into the database (job_log table).
    if (!empty($return['errors'])) return $return;
    
    $job_data = $this->repo_storage_controller->execute('getJobData', array($params['uuid']));

    // Throw a 404 if the job record doesn't exist.
    if (!$job_data) {
      $return['errors'][] = 'The Job record doesn\'t exist - import_csv()';
      return $return;
    }

    // Don't perform the metadata ingest if the job_status has been set to 'failed'.
    if ($job_data['job_status'] === 'failed') {
      $return['errors'][] = 'The job has failed. Exiting metadata ingest process.';
      return $return;
    }

    // Get user data.
    if( method_exists($this->tokenStorage, 'getUser') ) {
      $user = $this->tokenStorage->getToken()->getUser();
      $user_id = $user->getId();
    } else {
      $user_id = 0;
    }

    // Clear session data.
    $session = new Session();
    $session->remove('new_repository_ids_1');
    $session->remove('new_repository_ids_2');
    $session->remove('new_repository_ids_3');
    $session->remove('new_repository_ids_4');

    // Set the job type (e.g. subjects metadata import, items metadata import, capture datasets metadata import, models metadata import).
    // $job_data = $this->repo_storage_controller->execute('getJobData', array($params['uuid']));

    if (!empty($params['uuid']) && !empty($params['parent_project_id']) && !empty($params['parent_record_id']) && !empty($params['parent_record_type'])) {

      $job_info = (object)array(
        'job_id' => $job_data['job_id'],
        'uuid' => $params['uuid'],
        'parent_project_id' => $params['parent_project_id'],
        'parent_record_id' => $params['parent_record_id'],
      );

      // Remove 'metadata import' from the $job_data['job_type'].
      $job_type = str_replace(' metadata import', '', $job_data['job_type']);

      if (!empty($job_type)) {
        // Prepare the data.
        $data = $this->prepare_data($job_type, $this->project_directory . $this->uploads_directory . $job_info->uuid);

        // If $data is empty, then this is a Simple Ingest (we're not dealing with CSV's).
        if (empty($data)) $skip_ingest = true;

        // Ingest data.
        if (!empty($data)) {
          // Associate a Model to an Item
          // In order to associate a Model to an Item (normally a Model is associated to a Capture Dataset), need to:
          // 1) Get 'type' field values in the $data array.
          // 2) Then determine if there's an 'item' type and a 'model' type, but no 'capture_dataset' type.
          foreach ($data as $csv_key => $csv_value) {
            $csv_types[] = $csv_value['type'];
          }

          // Set the job_status to 'model' if that's the only CSV type being imported.
          if((count($csv_types) === 1) && in_array('model', $csv_types)) {
            $this->repo_storage_controller->execute('saveRecord', array(
              'base_table' => 'job',
              'record_id' => $job_info->job_id,
              'user_id' => 0,
              'values' => array(
                'job_type' => 'models metadata import',
              )
            ));
          }

          // Execute the ingest.
          $i = 1;
          foreach ($data as $csv_key => $csv_value) {
            // Don't perform an ingest without CSV data (an empty CSV).
            if(isset($csv_value['csv'])) {
              $return['job_log_ids'] = $this->ingest_csv_data($csv_value, $job_info, $params['parent_record_type'], $i);
            }

            $i++;
          }
        }
      }
    }

    // Update the job table to indicate that the CSV import failed.
    if (!$skip_ingest && !empty($params['uuid']) && empty($return['job_log_ids'])) {
      $this->repo_storage_controller->execute('saveRecord', array(
        'base_table' => 'job',
        'record_id' => $job_data['job_id'],
        'user_id' => $user_id,
        'values' => array(
          'job_status' => 'failed',
          'date_completed' => date('Y-m-d H:i:s'),
          'qa_required' => 0,
          'qa_approved_time' => null,
        )
      ));
      // Populate the errors array to return to front end.
      $return['errors'][] = 'Metadata ingest failed. Job ID: ' . $job_data['job_id'];
    } else {
      // Update the job table to set the status from 'metadata ingest in progress' to 'file transfer in progress'.
      $this->repo_storage_controller->execute('saveRecord', array(
        'base_table' => 'job',
        'record_id' => $job_data['job_id'],
        'user_id' => $user_id,
        'values' => array(
          'job_status' => 'file transfer in progress',
          'date_completed' => date('Y-m-d H:i:s'),
          'qa_required' => 0,
          'qa_approved_time' => null,
        )
      ));
    }

    // $this->addFlash('message', '<strong>Upload Succeeded!</strong> Files will be validated shortly. The validation scheduled task runs every 30 seconds, but it may take time to grind through the validation process. Please check back!');

    return $return;
  }

  /**
   * Prepare Data
   *
   * @param string $job_type The job type (One of: subjects, items, capture datasets, models)
   * @param string $job_upload_directory The upload directory
   * @return array Import result and/or any messages
   */
  public function prepare_data($job_type = null, $job_upload_directory = null)
  {

    $data = array();
    $csv = array();

    if (!empty($job_upload_directory)) {

      $finder = new Finder();
      $finder->files()->in($job_upload_directory);

      // Prevent additional CSVs from being imported according to the $job_type.
      // Assign keys to each CSV, with projects first, subjects second, and items third.
      foreach ($finder as $file) {
        if (($job_type === 'subjects') && stristr($file->getFilename(), 'subjects')) {
          $csv[0]['type'] = 'subject';
          $csv[0]['data'] = $file->getContents();
        }
        if ((($job_type === 'subjects') || ($job_type === 'items')) && stristr($file->getFilename(), 'items')) {
          $csv[1]['type'] = 'item';
          $csv[1]['data'] = $file->getContents();
        }
        if ((($job_type === 'subjects') || ($job_type === 'items') || ($job_type === 'capture datasets') || ($job_type === 'models')) && stristr($file->getFilename(), 'capture_datasets')) {
          $csv[2]['type'] = 'capture_dataset';
          $csv[2]['data'] = $file->getContents();
        }
        if ((($job_type === 'subjects') || ($job_type === 'items') || ($job_type === 'capture datasets') || ($job_type === 'models')) && stristr($file->getFilename(), 'models')) {
          $csv[3]['type'] = 'model';
          $csv[3]['data'] = $file->getContents();
        }
      }

      if (!empty($csv)) {

        // Sort the CSV array by key.
        ksort($csv);
        // Re-index the CSV array.
        $csv = array_values($csv);

        foreach ($csv as $csv_key => $csv_value) {

          // Convert the CSV to JSON.
          $array = array_map('str_getcsv', explode("\n", $csv_value['data']));
          $json = json_encode($array);

          // Convert the JSON to a PHP array.
          $json_array = json_decode($json, false);
          // Add the type to the array.
          $json_array['type'] = $csv_value['type'];

          // Read the first key from the array, which is the column headers.
          $target_fields = $json_array[0];

          // Remove the column headers from the array.
          array_shift($json_array);

          foreach ($json_array as $key => $value) {
            // Replace numeric keys with field names.
            if (is_numeric($key)) {
              foreach ($value as $k => $v) {

                $field_name = $target_fields[$k];

                unset($json_array[$key][$k]);

                // If present, bring the project_repository_id into the array.
                // $json_array[$key][$field_name] = ($field_name === 'project_repository_id') ? (int)$id : null;

                // Set the value of the field name.
                $json_array[$key][$field_name] = $v;

                // ITEM LOOKUPS
                // Look-up the ID for the 'item_type'.
                if ($field_name === 'item_type') {
                  $item_type_lookup_options = $this->itemsController->get_item_types();
                  $json_array[$key][$field_name] = (int)$item_type_lookup_options[$v];
                }

                // CAPTURE DATASET LOOKUPS
                // Look-up the ID for the 'capture_method'.
                if ($field_name === 'capture_method') {
                  $capture_method_lookup_options = $this->datasetsController->get_capture_methods();
                  $json_array[$key][$field_name] = (int)$capture_method_lookup_options[$v];
                }

                // Look-up the ID for the 'capture_dataset_type'.
                if ($field_name === 'capture_dataset_type') {
                  $capture_dataset_type_lookup_options = $this->datasetsController->get_dataset_types();
                  $json_array[$key][$field_name] = (int)$capture_dataset_type_lookup_options[$v];
                }

                // Look-up the ID for the 'item_position_type'.
                if ($field_name === 'item_position_type') {
                  $item_position_type_lookup_options = $this->datasetsController->get_item_position_types();
                  $json_array[$key][$field_name] = (int)$item_position_type_lookup_options[$v];
                }

                // Look-up the ID for the 'focus_type'.
                if ($field_name === 'focus_type') {
                  $focus_type_lookup_options = $this->datasetsController->get_focus_types();
                  $json_array[$key][$field_name] = (int)$focus_type_lookup_options[$v];
                }

                // Look-up the ID for the 'light_source_type'.
                if ($field_name === 'light_source_type') {
                  $light_source_type_lookup_options = $this->datasetsController->get_light_source_types();
                  $json_array[$key][$field_name] = (int)$light_source_type_lookup_options[$v];
                }

                // Look-up the ID for the 'background_removal_method'.
                if ($field_name === 'background_removal_method') {
                  $background_removal_method_lookup_options = $this->datasetsController->get_background_removal_methods();
                  $json_array[$key][$field_name] = (int)$background_removal_method_lookup_options[$v];
                }

                // Look-up the ID for the 'cluster_type'.
                if ($field_name === 'cluster_type') {
                  $camera_cluster_types_lookup_options = $this->datasetsController->get_camera_cluster_types();
                  $json_array[$key][$field_name] = (int)$camera_cluster_types_lookup_options[$v];
                }

                // MODEL LOOKUPS
                // TODO:
                // Model lookup options not in database! Need to either
                // 1) place into database and create a way to manage
                // 2) convert all lookups to draw from the JSON schema (preferred!)

                // Look-up the ID for the 'creation_method'.
                if ($field_name === 'creation_method') {
                  $creation_method_lookup_options = array('scan-to-mesh' => 1, 'CAD' => 2);
                  $json_array[$key][$field_name] = (int)$creation_method_lookup_options[$v];
                }

                // Look-up the ID for the 'model_modality'.
                if ($field_name === 'model_modality') {
                  $model_modality_lookup_options = array('point_cloud' => 1, 'mesh' => 2);
                  $json_array[$key][$field_name] = (int)$model_modality_lookup_options[$v];
                }

                // Look-up the ID for the 'units'.
                if ($field_name === 'units') {
                  $units_lookup_options = $this->modelsController->get_unit();
                  $json_array[$key][$field_name] = (int)$units_lookup_options[$v];
                }

                // Look-up the ID for the 'model_purpose'.
                if ($field_name === 'model_purpose') {
                  $model_purpose_lookup_options = array('master' => 1, 'delivery_web' => 2, 'delivery_print' => 3, 'intermediate_processing_step' => 4);
                  $json_array[$key][$field_name] = (int)$model_purpose_lookup_options[$v];
                }

              }

              // If an array of data contains 1 or fewer keys, then it means the row is empty.
              // Unset the empty row, so it doesn't get inserted into the database.
              if (count(array_keys((array)$json_array[$key])) > 1) {
                // Convert the array to an object.
                $data[$csv_key]['csv'][] = (object)$json_array[$key];
              }
              
            }

            if (!is_numeric($key)) {
              $data[$csv_key]['type'] = $value;
            }
          }

        }

      }

    }

    return $data;
  }

  /**
   * Ingest CSV Data
   *
   * @param obj $data  Data object
   * @param array $job_data  Job data
   * @param string $parent_record_type  Parent record type
   * @param int $i  Iterator
   * @return array  An array of job log IDs
   */
  public function ingest_csv_data($data = null, $job_data = array(), $parent_record_type = null, $i = 1)
  {

    $session = new Session();
    $data = (object)$data;
    $job_log_ids = array();
    $job_status = 'finished';

    // Get user data.
    if( method_exists($this->tokenStorage, 'getUser') ) {
      $user = $this->tokenStorage->getToken()->getUser();
      $data->user_id = $user->getId();
    } else {
      $data->user_id = 0;
    }

    // Job ID and parent record ID
    $data->job_id = isset($job_data->job_id) ? $job_data->job_id : false;
    $data->uuid = isset($job_data->uuid) ? $job_data->uuid : false;
    $data->parent_project_id = isset($job_data->parent_project_id) ? $job_data->parent_project_id : false;
    $data->parent_record_id = isset($job_data->parent_record_id) ? $job_data->parent_record_id : false;
    $data->parent_record_type = isset($parent_record_type) ? $parent_record_type : false;

    // Just in case: throw a 404 if either job ID or parent record ID aren't passed.
    if (!$data->job_id) throw $this->createNotFoundException('Job ID not provided.');
    if (!$data->uuid) throw $this->createNotFoundException('UUID not provided.');
    if (!$data->parent_project_id) throw $this->createNotFoundException('Parent Project record ID not provided.');
    if (!$data->parent_record_id) throw $this->createNotFoundException('Parent record ID not provided.');

    // Check to see if the parent project record exists/active, and if it doesn't, throw a createNotFoundException (404).
    if (!empty($data->parent_project_id)) {
      $project = $this->repo_storage_controller->execute('getProject', array('project_repository_id' => $data->parent_project_id));
      // If no project is returned, throw a createNotFoundException (404).
      if (!$project) throw $this->createNotFoundException('The Project record doesn\'t exist');
    }

    // $data->type is referred to extensively throughout the logic.
    // $data->type can be one of: subject, item, capture_dataset, model

    // Insert into the job_log table
    $job_log_ids[] = $this->repo_storage_controller->execute('saveRecord', array(
      'base_table' => 'job_log',
      'user_id' => $data->user_id,
      'values' => array(
        'job_id' => $data->job_id,
        'job_log_status' => 'start',
        'job_log_label' => 'Import ' . $data->type,
        'job_log_description' => 'Import started',
      )
    ));

    // If data type is not a 'subject', set the array of $new_repository_ids.
    if ($data->type !== 'subject') {
      $new_repository_ids[$i] = $session->get('new_repository_ids_' . ($i-1));
    }

    // Extract subject, item, and model database column data from the processing server's 'inspect-mesh' results.
    if ($data->type === 'model') {
      $data = $this->extract_data_from_external('get_model_data_from_processing_service_results', $data);
    }

    // Extract subject and capture_dataset database column data from file names.
    $data = $this->extract_data_from_external('get_data_from_file_names', $data);

    // foreach() begins
    foreach ($data->csv as $csv_key => $csv_val) {

      // If the import_row_id is missing, set the job to failed and set the error.
      if (!isset($csv_val->import_row_id)) {
        $job_status = 'failed';
        $error = array($data->type . ' CSV is missing the import_row_id column');
      }

      // If this is not a subject, and the import_parent_id is missing, set the job to failed and set the error.
      if (($data->type !== 'subject') && !isset($csv_val->import_parent_id)) {
        $job_status = 'failed';
        $error = array($data->type . ' CSV is missing the import_parent_id column');
      }

      if ($job_status === 'failed') {
        // Log the error to the database.
        $this->repoValidate->logErrors(
          array(
            'job_id' => $data->job_id,
            'user_id' => 0,
            'job_log_label' => 'Metadata Ingest',
            'errors' => $error,
          )
        );
        // Update the 'job_status' in the 'job' table accordingly.
        $this->repo_storage_controller->execute('setJobStatus', 
          array(
            'job_id' => $data->uuid, 
            'status' => $job_status,
            'date_completed' => date('Y-m-d h:i:s')
          )
        );
        break;
      }

      if (isset($csv_val->import_row_id)) {
        // Set the parent record's repository ID.
        switch ($data->type) {
          case 'subject':
            // Set the project_repository_id
            $csv_val->project_repository_id = (int)$data->parent_project_id;
            break;
          case 'item':
            // Set the subject_repository_id.
            if (!empty($new_repository_ids[$i]) && !empty($csv_val->import_parent_id)) {
              $csv_val->subject_repository_id = $new_repository_ids[$i][$csv_val->import_parent_id];
            } else {
              $csv_val->subject_repository_id = $data->parent_record_id;
            }
            break;
          case 'capture_dataset':
            // Set the parent_item_repository_id.
            if (!empty($new_repository_ids[$i]) && !empty($csv_val->import_parent_id)) {
              $csv_val->parent_item_repository_id = $new_repository_ids[$i][$csv_val->import_parent_id];
            } else {
              $csv_val->parent_item_repository_id = $data->parent_record_id;
            }

            // Get the parent project ID.
            $parent_records = $this->repo_storage_controller->execute('getParentRecords', array(
              'base_record_id' => $csv_val->parent_item_repository_id,
              'record_type' => 'item',
            ));
            if (!empty($parent_records)) {
              $csv_val->parent_project_repository_id = $parent_records['project_repository_id'];
            }
            break;
          case 'model':
            // 1) Append the job ID to the file path
            // 2) Add the file's checksum to the $csv_val object.
            if(!empty($csv_val->file_path)) {
              // Append the job ID to the file path.
              $csv_val->file_path = '/' . $data->uuid . $csv_val->file_path;
              // Get the file's checksum from the BagIt manifest.
              $finder = new Finder();
              $finder->files()->in($this->project_directory . $this->uploads_directory . $data->uuid . '/');
              $finder->files()->name('manifest*.txt');
              // Find the manifest file.
              foreach ($finder as $file) {
                $manifest_contents = $file->getContents();
                $manifest_lines = preg_split('/\r\n|\n|\r/', trim($manifest_contents));
                foreach ($manifest_lines as $mkey => $mvalue) {
                  $manifest_line_array = preg_split('/\s+/', $mvalue);
                  // If there's a match against file paths, add the checksum to the $csv_val object.
                  if (strstr($csv_val->file_path, $manifest_line_array[1])) {
                    $csv_val->file_checksum = $manifest_line_array[0];
                    break;
                  }
                }
              }
            }

            // /////////////////////////////////////////////////////////////////////////////////////////
            // // Extract database column data from the processing server's 'inspect-mesh' results.
            // $csv_val = $this->extract_data_from_external('get_model_data_from_processing_service', $csv_val, $data);
            // /////////////////////////////////////////////////////////////////////////////////////////

            // Set the parent_capture_dataset_repository_id or parent_item_repository_id (when a model is associated to an item).
            // TODO: add previous_parent_record_type to the mix, 
            // so the system will automatically detect what to associate a model to (to make it a bit more bullet-proof).
            if (!empty($new_repository_ids[$i]) && !empty($csv_val->import_parent_id)) {
              // If a model maps to an item, set the value for the 'parent_item_repository_id' field.
              if ($data->parent_record_type === 'item') {
                $csv_val->parent_item_repository_id = $new_repository_ids[$i][$csv_val->import_parent_id];
              }
              // Otherwise, set the value for the 'parent_capture_dataset_repository_id' field.
              else {
                $csv_val->parent_capture_dataset_repository_id = $new_repository_ids[$i][$csv_val->import_parent_id];
              }
            } else {
              // If a model maps to an item, set the value for the 'parent_item_repository_id' field.
              if ($data->parent_record_type === 'item') {
                $csv_val->parent_item_repository_id = $data->parent_record_id;
              }
              // Otherwise, set the value for the 'parent_capture_dataset_repository_id' field.
              else {
                $csv_val->parent_capture_dataset_repository_id = $data->parent_record_id;
              }
            }
            break;
        }

        // Insert data from the CSV into the appropriate database table, using the $data->type as the table name.
        $this_id = $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => $data->type,
          'user_id' => $data->user_id,
          'values' => (array)$csv_val
        ));

        // Insert capture data elements and capture data files into the metadata storage.
        if (($data->type === 'capture_dataset') && isset($csv_val->capture_data_elements) && !empty($csv_val->capture_data_elements)) {
          $this->insert_capture_data_elements_and_files($csv_val->capture_data_elements, $this_id, $data);
        }

        // Create an array of all of the newly created repository IDs.
        $new_new_repository_ids[$csv_val->import_row_id] = $this_id;

        // Set the description for the job log.
        switch ($data->type) {
          case 'subject':
            $data->description = $csv_val->local_subject_id . ' - ' . $csv_val->subject_name;
            break;
          case 'item':
            $data->description = $csv_val->item_description;
            break;
          case 'capture_dataset':
            $data->description = $data->for_model_description = $csv_val->capture_dataset_name;
            break;
          case 'model':
            $data->description = $project['project_name'] . ' - ' . $csv_val->model_file_type;
            break;
        }

        // Insert into the job_import_record table
        $job_import_record_id = $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => 'job_import_record',
          'user_id' => $data->user_id,
          'values' => array(
            'job_id' => $data->job_id,
            'record_id' => $this_id,
            'project_id' => (int)$data->parent_project_id,
            'record_table' => $data->type,
            'description' => $data->description,
          )
        ));
      }
    }
    // foreach() ends

    if (isset($new_new_repository_ids) && !empty($new_new_repository_ids)) {
      // Set the session variable 'new_repository_ids'.
      $session->set('new_repository_ids_' . $i, $new_new_repository_ids);
    }

    // Job data.
    $job_data = $this->repo_storage_controller->execute('getJobData', array($data->uuid));

    if ($job_data['job_status'] !== 'failed') {
      // Insert into the job_log table
      // TODO: Feed the 'job_log_label' to the log leveraging fields from a form submission in the UI.
      $job_log_ids[] = $this->repo_storage_controller->execute('saveRecord', array(
        'base_table' => 'job_log',
        'user_id' => $data->user_id,
        'values' => array(
          'job_id' => $data->job_id,
          'job_log_status' => $job_status,
          'job_log_label' => 'Import ' . $data->type,
          'job_log_description' => 'Import ' . $job_status,
        )
      ));
    }

    // If the $job_data['job_status'] is failed, remove $job_log_ids.
    if ($job_data['job_status'] === 'failed') $job_log_ids = array();

    // TODO: return something more than job log IDs?
    return $job_log_ids;
  }

  /**
   * Insert Capture Data Elements
   *
   * @param array $capture_data_elements An array of capture data elements.
   * @param int $capture_dataset_repository_id The capture dataset repository ID
   * @param array $data Job data
   * @return null
   */
  public function insert_capture_data_elements_and_files($capture_data_elements = array(), $capture_dataset_repository_id = null, $data = array()) {

    if (!empty($capture_data_elements) && !empty($capture_dataset_repository_id) && !empty($data)) {
      // Loop through capture data elements and add to storage.
      foreach ($capture_data_elements as $ekey => $evalue) {

        // Pluck-out capture data files and save for the next step.
        if(isset($evalue['capture_data_files']) && !empty($evalue['capture_data_files'])) {
          $capture_data_files = $evalue['capture_data_files'];
          unset($evalue['capture_data_files']);
        }

        // Set the parent capture dataset ID.
        $evalue['capture_dataset_repository_id'] = $capture_dataset_repository_id;
        // Add to metadata storage.
        $capture_data_element_id = $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => 'capture_data_element',
          'user_id' => $data->user_id,
          'values' => $evalue
        ));

        // Insert into the job_import_record table
        $this->repo_storage_controller->execute('saveRecord', array(
          'base_table' => 'job_import_record',
          'user_id' => $data->user_id,
          'values' => array(
            'job_id' => $data->job_id,
            'record_id' => $capture_data_element_id,
            'project_id' => (int)$data->parent_project_id,
            'record_table' => 'capture_data_element',
            'description' => 'imported capture data element',
          )
        ));

        // Loop through capture data files and add to storage.
        foreach ($capture_data_files as $fkey => $fvalue) {
          // Set the parent capture data element ID.
          $fvalue['parent_capture_data_element_repository_id'] = $capture_data_element_id;
          // Add to metadata storage.
          $capture_data_file_id = $this->repo_storage_controller->execute('saveRecord', array(
            'base_table' => 'capture_data_file',
            'user_id' => $data->user_id,
            'values' => $fvalue
          ));

          // Insert into the job_import_record table
          $this->repo_storage_controller->execute('saveRecord', array(
            'base_table' => 'job_import_record',
            'user_id' => $data->user_id,
            'values' => array(
              'job_id' => $data->job_id,
              'record_id' => $capture_data_file_id,
              'project_id' => (int)$data->parent_project_id,
              'record_table' => 'capture_data_file',
              'description' => 'imported capture data file',
            )
          ));
        }

      }
    }

  }

  /**
   * Extract Data From External
   *
   * @param string $function_name Name of the function to call.
   * @param string $data Job data
   * @return array
   */
  public function extract_data_from_external($function_name = null, $data = array())
  {

    if (!empty($function_name) && !empty($data) && method_exists($this, $function_name)) {
      $data = $this->$function_name($data);
    }

    return $data;
  }

  /**
   * Get Model Data From Processing Service Results
   *
   * @param array $data Job data
   * @return array
   */
  public function get_model_data_from_processing_service_results($data = array())
  {
    // Extract database column data from the processing server's 'inspect-mesh' results.
    // Query the database for 'inspect-mesh' jobs.
    if (!empty($data)) {

      $repo_processing_job_data = $this->repo_storage_controller->execute('getRecords', array(
        'base_table' => 'processing_job',
        'fields' => array(
          array(
            'table_name' => 'processing_job_file',
            'field_name' => 'job_id',
          ),
          array(
            'table_name' => 'processing_job_file',
            'field_name' => 'file_contents',
          ),
        ),
        // Joins
        'related_tables' => array(
          array(
            'table_name' => 'processing_job_file',
            'table_join_field' => 'job_id',
            'join_type' => 'LEFT JOIN',
            'base_join_table' => 'processing_job',
            'base_join_field' => 'processing_service_job_id',
          )
        ),
        'limit' => 1,
        'search_params' => array(
          0 => array('field_names' => array('processing_job.processing_service_job_id'), 'search_values' => array($data->job_id), 'comparison' => '='),
          1 => array('field_names' => array('processing_job.recipe'), 'search_values' => array('inspect-mesh'), 'comparison' => '='),
          2 => array('field_names' => array('processing_job.state'), 'search_values' => array('done'), 'comparison' => '='),
          3 => array('field_names' => array('processing_job_file.file_name'), 'search_values' => array('-report.json'), 'comparison' => 'LIKE'),
        ),
        'search_type' => 'AND',

        'omit_active_field' => true,
        )
      );

      foreach ($repo_processing_job_data as $key => $value) {
        // Get the processing job's model-report.json file's contents.
        $file_contents = json_decode($value['file_contents'], true);
        $model_file_name = $file_contents['parameters']['meshFile'];

        foreach ($data->csv as $csv_key => $csv_val) {
          // If the processing service's $model_file_name is found in the repository's file_path,
          // add values from the model-report.json file's contents.
          if(stristr($csv_val->file_path, $model_file_name)) {
            // Break-out the topology and statistics into dedicated variables (mainly for readability).
            $topology = $file_contents['steps']['inspect']['result']['inspection']['topology'];
            $statistics = $file_contents['steps']['inspect']['result']['inspection']['statistics'];
            // Determine the model_modality (type of geometry) - 'point_cloud' or a 'mesh'.
            $data->csv[$csv_key]->model_modality = (($statistics['numFaces'] === 0) && ($statistics['numEdges'] === 0)) ? 'point_cloud' : 'mesh';
            $data->csv[$csv_key]->is_watertight = $topology['isWatertight'];
            $data->csv[$csv_key]->has_normals = $statistics['hasNormals'];
            $data->csv[$csv_key]->face_count = $statistics['numFaces'];
            $data->csv[$csv_key]->vertices_count = $statistics['numVertices'];
            $data->csv[$csv_key]->has_vertex_color = $statistics['hasVertexColors'];
            $data->csv[$csv_key]->has_uv_space = $statistics['hasTexCoords'];
          }
        }

      }
    }

    return $data;
  }

  /**
   * Get Data From File Names
   *
   * @param array $data Job data
   * @return array
   */
  public function get_data_from_file_names($data = array())
  {

    $image_file_names = $model_file_names = array();
    $capture_datasets = $models = array();

    if (!empty($data)) {

      // Get the file's checksum from the BagIt manifest.
      $finder = new Finder();
      $finder->files()->in($this->project_directory . $this->uploads_directory . $data->uuid . '/');
      // Loop through uploaded files.
      foreach ($finder as $file) {

        // Get the parent directory.
        $dir = dirname($file->getPathname(), 1);
        $dir_parts = explode(DIRECTORY_SEPARATOR, $dir);
        $dir_parent = array_pop($dir_parts);

        // Get image files.
        // If this file's extension exists in the $this->image_extensions array, add to the $images array.
        if (in_array($file->getExtension(), $this->image_extensions)) {
          $image_file_names[$dir_parent][] = str_replace('.' . $file->getExtension(), '', $file->getFilename());
        }

        // Get model files.
        // If this file's extension exists in the $this->model_extensions array, add to the $models array.
        if (in_array($file->getExtension(), $this->model_extensions)) {
          $model_file_names[] = str_replace('.' . $file->getExtension(), '', $file->getFilename());
        }
      }

      // Remove duplicate image file names and sort.
      foreach ($image_file_names as $ikey => $ivalue) {
        $image_file_names[$ikey] = array_unique($image_file_names[$ikey]);
        sort($image_file_names[$ikey]);
      }

      if (!empty($image_file_names)) {
        ksort($image_file_names);
        $data = $this->get_dataset_data_from_filenames($image_file_names, $data);
      }

      // Remove duplicate model file names and sort.
      $model_file_names = array_unique($model_file_names);
      sort($model_file_names);
      if (!empty($model_file_names)) {
        $data = $this->get_model_data_from_filenames($model_file_names, $data);
      }

    }

    return $data;
  }

  /**
   * Get Capture Dataset Data From Filenames
   *
   * @param array $image_file_names Image file names
   * @param array $data Job data
   * @return array
   */
  public function get_dataset_data_from_filenames($image_file_names = array(), $data = array())
  {
    // Insert into subject (local_subject_id)
    // Insert into capture_dataset (capture_dataset_field_id)
    // Insert into capture_data_element (position_in_cluster_field_id, cluster_position_field_id)
    //
    // Example file name mapping:
    // usnm_160-s01-p01-01.jpg
    // [local_subject_id]-s[capture_dataset_field_id]-p[position_in_cluster_field_id]-[cluster_position_field_id].jpg

    if (!empty($image_file_names) && !empty($data)) {

      // If there's a file_name_map.csv file at the root of the 'data' directory, use it.
      $file_name_map_main = $this->get_filename_map($data);

      // Add data to uploaded CSVs.
      switch ($data->type) {
        case 'subject':
          // Grab the first file name to get the local_subject_id.
          foreach ($image_file_names as $dir_name => $files) {
            if (!empty($files)) {
              // Get the file's info from the metadata storage.
              $file_info = $this->get_file_info($data->uuid, $files[0]);
              // Get the file name map, if one exists in this directory.
              if (!empty($file_info)) {
                $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                // If no file name map exists, use the main one in the root of the 'data' directory.
                $file_name_map = !empty($file_name_map) ? $file_name_map : $file_name_map_main;
              }
              // Establish the map key so we know which slot in the file name to obtain the data from.
              $key = (isset($file_name_map) && array_search('local_subject_id', $file_name_map)) ? array_search('local_subject_id', $file_name_map) : array_search('local_subject_id', $this->default_image_file_name_map);
              // Transform the file name to an array.
              $file_name_parts = explode('-', $files[0]);
              // Populate the CSV's subject entries with the local_subject_id from the file name.
              foreach ($data->csv as $ck => $cv) {
                $data->csv[$ck]->local_subject_id = $file_name_parts[ $key ];
              }
            }
          }
          break;
        case 'capture_dataset':

          $i = 0;
          foreach ($image_file_names as $dir_name => $files) {

            // Get the capture_dataset_field_id from the directory name.
            $data->csv[$i]->capture_dataset_field_id = preg_replace('/[^0-9]/', '', $dir_name);

            // Add 'capture_data_elements' and 'capture_data_files' to the 'capture_dataset' CSV.
            if (!empty($files)) {
              foreach ($files as $file) {

                // Get the file's info from the metadata storage.
                $file_info = $this->get_file_info($data->uuid, $file);

                // Get the file name map, if one exists in this directory.
                $file_name_map = array();
                // if (!empty($file_info)) $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                if (!empty($file_info)) {
                  $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                  // If no file name map exists, use the main one in the root of the 'data' directory.
                  $file_name_map = !empty($file_name_map) ? $file_name_map : $file_name_map_main;
                }

                // $this->u->dumper($file_name_map,0);

                // Establish the file name map keys so we know which slot in the file name to obtain the data from.
                // Default position_in_cluster_field_id key.
                $key1 = array_search('position_in_cluster_field_id', $this->default_image_file_name_map);
                // Default cluster_position_field_id key.
                $key2 = array_search('cluster_position_field_id', $this->default_image_file_name_map);
                // If the $file_name_map exists, then set the key using that.
                if (!empty($file_name_map)) {
                  // User-supplied position_in_cluster_field_id key.
                  $key1 = array_search('position_in_cluster_field_id', $file_name_map)
                      ? array_search('position_in_cluster_field_id', $file_name_map)
                      : null;
                  // User-supplied cluster_position_field_id key.
                  $key2 = array_search('cluster_position_field_id', $file_name_map)
                      ? array_search('cluster_position_field_id', $file_name_map)
                      : null;
                }

                // $this->u->dumper($file_name_map,0);
                // $this->u->dumper($key1,0);
                // $this->u->dumper($key2);

                // Transform the file name to an array.
                $file_name_parts = explode('-', $file);

                // Build-out the $capture_data_files array.
                if (!empty($file_info)) {
                  $capture_data_files = array();
                  foreach ($file_info as $file_info_key => $file_info_value) {
                    // File info for the capture_data_file columns
                    $capture_data_files[] = array(
                      'file_upload_id' => $file_info_value['file_upload_id'],
                      'capture_data_file_name' => $file_info_value['file_name'],
                      'capture_data_file_type' => $file_info_value['file_type'],
                      'is_compressed_multiple_files' => 0,
                    );
                  }
                }

                // Build-out the $capture_data_elements array, adding in this capture data element's $capture_data_files array.
                $data->csv[$i]->capture_data_elements[] = array(
                  'position_in_cluster_field_id' => (!empty($key1) && stristr($file_name_parts[ $key1 ], 'p')) ? (int)str_replace('p', '', $file_name_parts[ $key1 ]) : null,
                  'cluster_position_field_id' => (!empty($key2) && isset($file_name_parts[ $key2 ])) ? (int)$file_name_parts[ $key2 ] : null,
                  'capture_data_files' => $capture_data_files,
                );

              }
            }

            $i++;
          }
          break;
      }

    }

    return $data;
  }

  /**
   * Get Data From File Names
   *
   * @param array $model_file_names Model file names
   * @param array $data Job data
   * @return array
   */
  public function get_model_data_from_filenames($model_file_names = array(), $data = array())
  {
    // Insert into subject (local_subject_id)
    // Insert into item (item_description)
    // Insert into model (model_purpose)
    //
    // Example file name mapping:
    // nmnh-usnm_v_512384522-skull-master_model-2018_10_22.obj
    // nmnh-[local_subject_id]-[item_description]-[model_purpose]-2018_10_22.obj

    if (!empty($model_file_names)) {

      // If there's a file_name_map.csv file at the root of the 'data' directory, use it.
      $file_name_map_main = $this->get_filename_map($data);

      // Add data to uploaded CSVs.
      switch ($data->type) {
        case 'subject':
          // Get the local_subject_id from the file name.
          foreach ($model_file_names as $key => $file) {
            if (!empty($file)) {
              // Get the file's info from the metadata storage.
              $file_info = $this->get_file_info($data->uuid, $file);
              // Get the file name map, if one exists in this directory.
              if (!empty($file_info)) {
                $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                // If no file name map exists, use the main one in the root of the 'data' directory.
                $file_name_map = !empty($file_name_map) ? $file_name_map : $file_name_map_main;
              }
              // Establish the file name map key so we know which slot in the file name to obtain the data from.
              // local_subject_id
              $key1 = (isset($file_name_map) && array_search('local_subject_id', $file_name_map))
                  ? array_search('local_subject_id', $file_name_map)
                  : array_search('local_subject_id', $this->default_model_file_name_map);
              // Transform the file name to an array.
              $file_name_parts = explode('-', $file);
              // Populate the CSV's subject entries with the local_subject_id from the file name.
              foreach ($data->csv as $ck => $cv) {
                $data->csv[$ck]->local_subject_id = $file_name_parts[$key1];
              }
            }
          }
          break;
        case 'item':
          // Get the item_description from the file name.
          foreach ($model_file_names as $key => $file) {
            if (!empty($file)) {
              // Get the file's info from the metadata storage.
              $file_info = $this->get_file_info($data->uuid, $file);
              // Get the file name map, if one exists in this directory.
              if (!empty($file_info)) {
                $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                // If no file name map exists, use the main one in the root of the 'data' directory.
                $file_name_map = !empty($file_name_map) ? $file_name_map : $file_name_map_main;
              }
              // Establish the file name map key so we know which slot in the file name to obtain the data from.
              // item_description
              $key1 = (isset($file_name_map) && array_search('item_description', $file_name_map))
                  ? array_search('item_description', $file_name_map)
                  : array_search('item_description', $this->default_model_file_name_map);
              // Transform the file name to an array.
              $file_name_parts = explode('-', $file);
              // Populate the CSV's item entries with the item_description from the file name.
              foreach ($data->csv as $ck => $cv) {
                $data->csv[$ck]->item_description = $file_name_parts[$key1];
              }
            }
          }
          break;
        case 'model':
          // Get the model_purpose from the file name.
          foreach ($model_file_names as $key => $file) {
            if (!empty($file)) {
              // Get the file's info from the metadata storage.
              $file_info = $this->get_file_info($data->uuid, $file);
              // Get the file name map, if one exists in this directory.
              if (!empty($file_info)) {
                $file_name_map = $this->get_filename_map($data, $file_info[0]['file_path']);
                // If no file name map exists, use the main one in the root of the 'data' directory.
                $file_name_map = !empty($file_name_map) ? $file_name_map : $file_name_map_main;
              }
              // Establish the file name map key so we know which slot in the file name to obtain the data from.
              // model_purpose
              $key1 = (isset($file_name_map) && array_search('model_purpose', $file_name_map))
                  ? array_search('model_purpose', $file_name_map)
                  : array_search('model_purpose', $this->default_model_file_name_map);
              // Transform the file name to an array.
              $file_name_parts = explode('-', $file);
              // Remove '_model' from the model_purpose chunk from the file name ('master_model' becomes 'model').
              $data->csv[$key]->model_purpose = str_replace('_model', '', $file_name_parts[$key1]);
            }
          }
          break;
      }

    }

    return $data;
  }

  /**
   * Get File Info
   *
   * @param string $uuid The job UUID
   * @param string $file_name The file name
   */
  public function get_file_info($uuid = null, $file_name = null)
  {
    $data = array();

    if (!empty($uuid) && !empty($file_name)) {
      // Query the metadata storage for the file's info using the job UUID and filename.
      $data = $this->repo_storage_controller->execute('getRecords', array(
        'base_table' => 'file_upload',
        'fields' => array(),
        'limit' => 1,
        'search_params' => array(
          0 => array('field_names' => array('file_upload.file_path'), 'search_values' => array($uuid), 'comparison' => 'LIKE'),
          1 => array('field_names' => array('file_upload.file_path'), 'search_values' => array($file_name), 'comparison' => 'LIKE'),
        ),
        'search_type' => 'AND',
        'omit_active_field' => true,
        )
      );
    }

    return $data;
  }

  /**
   * Get File Name Map
   *
   * @param array $job_data Job data
   * @param string $directory The target directory
   * @return array
   */
  public function get_filename_map($job_data = array(), $directory = '')
  {

    $data = array();
    $target_directory = $this->project_directory . $this->uploads_directory . $job_data->uuid;

    if (!empty($job_data)) {

      if (!empty($directory)) {
        // Remove the file name to get the parent directory.
        $file_path_array = explode(DIRECTORY_SEPARATOR, $directory);
        array_pop($file_path_array);
        $dir = implode(DIRECTORY_SEPARATOR, $file_path_array);
        $dir = str_replace(DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'repository' . DIRECTORY_SEPARATOR, '', $dir);
        $target_directory = $this->project_directory . $this->uploads_directory . $dir;
      }

      // $this->u->dumper($target_directory);

      // Find the file_name_map.csv
      $finder = new Finder();
      $finder->files()->in($target_directory);
      // By default, scan for the 'file_name_map.csv' file above the 'data' directory.
      // Don't want to find 'file_name_map.csv' overrides within the 'data' directory unless we specifically ask for it.
      if (empty($directory)) $finder->notPath('data' . DIRECTORY_SEPARATOR);
      $finder->files()->name('file_name_map.csv');

      foreach ($finder as $file) {
        // Just to make sure we're dealing with the correct file.
        if ($file->getFilename() === 'file_name_map.csv') {
          $contents = $file->getContents();
          // Remove spaces from the CSV if there are any.
          $contents = preg_replace('/\s+/', '', $contents);
          $data = explode(',', $contents);
        }
      }

    }

    return $data;
  }

}