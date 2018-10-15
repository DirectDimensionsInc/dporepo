<?php

namespace AppBundle\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;

use AppBundle\Utils\AppUtilities;

class RepoProcessingService implements RepoProcessingServiceInterface {

  /**
   * @var object $u
   */
  public $u;

  /**
   * @var object $kernel
   */
  public $kernel;

  /**
   * @var string $processing_service_location
   */
  private $processing_service_location;

  /**
   * @var string $processing_service_client_id
   */
  private $processing_service_client_id;

  /**
   * Constructor
   * @param object  $kernel  Symfony's kernel object
   * @param string  $processing_service_location  Processing service location (e.g. URL)
   * @param string  $processing_service_client_id  Processing service client ID
   */
  public function __construct(KernelInterface $kernel, string $processing_service_location, string $processing_service_client_id)
  {
    $this->u = new AppUtilities();
    // $this->u->dumper('hello');
    $this->processing_service_location = $processing_service_location;
    $this->processing_service_client_id = $processing_service_client_id;
  }

  /**
   * Get recipes
   *
   * @return array
   */
  public function get_recipes() {

    $data = array();

    // /recipes
    $params = array(
      'recipes',
    );

    $data = $this->query_api($params, 'GET');

    return $data;
  }

  /**
   * Get recipe by name
   *
   * @param string $recipe_name
   * @return array
   */
  public function get_recipe_by_name($recipe_name = null) {

    $data = array();

    if (empty($recipe_name)) {
      $data['error'] = 'Error: Missing parameter(s). Required parameters: recipe_name';
    }

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      $recipes = $this->get_recipes();

      if ($recipes['httpcode'] === 200) {
        // Get all recipes.
        $recipes_array = json_decode($recipes['result'], true);
        // Loop through recipes to find the target recipe.
        foreach ($recipes_array as $key => $value) {
          if ($value['name'] === $recipe_name) {
            $data = $value;
          } 
        }
        // Set an error if the recipe can't be found.
        if (empty($data)) $data['error'] = 'Error: Recipe doesn\'t exist';
      } else {
        // Set an error if the recipes endpoint returns something other than a 200 HTTP code.
        $data['error'] = 'Error: Could not retrieve recipes.';
        $data['error'] .= 'HTTP code: ' . $recipes['httpcode'];
        $data['error'] .= 'Response header: ' . $recipes['response_header'];
      }

    }

    return $data;
  }

  /**
   * Post job
   *
   * @param string $recipe_id
   * @param string $job_name
   * @param string $file_name
   * @return array
   */
  public function post_job($recipe_id = null, $job_name = null, $file_name = null) {

    $data = array();

    if (empty($recipe_id) || empty($job_name) || empty($file_name)) {
      $data['error'] = 'Error: Missing parameter(s). Required parameters: recipe_id, job_name, file_name';
    }

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      $params = array(
        'job',
      );

      $post_params = array(
        'id' => $this->create_guid(),
        'name' => $job_name,
        'clientId' => $this->processing_service_client_id,
        'recipeId' => $recipe_id,
        'parameters' => array(
          'meshFile' => $file_name
        ),
        'priority' => 'normal',
        'submission' => str_replace('+00:00', 'Z', gmdate('c', strtotime('now'))),
      );

      // API returns 200 for a successful POST,
      // and a 404 for an unsuccessful POST. 
      $data = $this->query_api($params, 'POST', $post_params);
    }

    return $data;
  }

  /**
   * Run job
   *
   * @param $job_id
   * @return array
   */
  public function run_job($job_id = null) {

    $data = array();

    if (empty($job_id)) $data['error'] = 'Error: Missing parameter. Required parameters: job_id';

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      // /clients/{clientId}/jobs/{jobId}/run
      $params = array(
        'clients',
        $this->processing_service_client_id,
        'jobs',
        $job_id,
        'run'
      );

      $data = $this->query_api($params, 'PATCH');
    }

    return $data;
  }

  /**
   * Cancel job
   *
   * @param $job_id
   * @return array
   */
  public function cancel_job($job_id = null) {

    $data = array();

    if (empty($job_id)) $data['error'] = 'Error: Missing parameter. Required parameters: job_id';

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      // /clients/{clientId}/jobs/{jobId}/cancel
      $params = array(
        'clients',
        $this->processing_service_client_id,
        'jobs',
        $job_id,
        'cancel'
      );

      $data = $this->query_api($params, 'PATCH');
    }

    return $data;
  }

  /**
   * Delete job
   *
   * @param $job_id
   * @return array
   */
  public function delete_job($job_id = null) {

    $data = array();

    if (empty($job_id)) $data['error'] = 'Error: Missing parameter. Required parameters: job_id';

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      // /clients/{clientId}/jobs/{jobId}
      $params = array(
        'clients',
        $this->processing_service_client_id,
        'jobs',
        $job_id
      );

      // API returns 200 for a successful DELETE.
      $data = $this->query_api($params, 'DELETE');
    }

    return $data;
  }

  /**
   * Get job
   *
   * @param $job_id
   * @return array
   */
  public function get_job($job_id = null) {

    $data = array();

    if (empty($job_id)) $data['error'] = 'Error: Missing parameter. Required parameters: job_id';

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      // /clients/{clientId}/jobs/{jobId}
      $params = array(
        'clients',
        $this->processing_service_client_id,
        'jobs',
        $job_id
      );

      // API returns 200 and one job's data for a successful GET,
      // and a 400 and error message for an unsuccessful GET.
      $data = $this->query_api($params, 'GET');
    }

    return $data;
  }

  /**
   * Get jobs
   *
   * @return array
   */
  public function get_jobs() {

    $data = array();

    // /clients/{clientId}/jobs
    $params = array(
      'clients',
      $this->processing_service_client_id,
      'jobs'
    );

    // API returns 200 and all job data for a successful GET,
    // and a 200 for an unsuccessful GET (for an invalid client ID).
    $data = $this->query_api($params, 'GET');

    return $data;
  }

  /**
   * Get job by name
   *
   * @param string $job_name
   * @return array
   */
  public function get_job_by_name($job_name = null) {

    $data = array();

    if (empty($job_name)) {
      $data['error'] = 'Error: Missing parameter(s). Required parameters: job_name';
    }

    // If there are no errors, execute the API call.
    if (empty($data['error'])) {

      $recipes = $this->get_jobs();

      if ($recipes['httpcode'] === 200) {
        // Get all recipes.
        $jobs_array = json_decode($recipes['result'], true);
        // Loop through recipes to find the target recipe.
        foreach ($jobs_array as $key => $value) {
          if ($value['name'] === $job_name) {
            $data = $value;
          } 
        }
        // Set an error if the recipe can't be found.
        if (empty($data)) $data['error'] = 'Error: Job doesn\'t exist';
      } else {
        // Set an error if the recipes endpoint returns something other than a 200 HTTP code.
        $data['error'] = 'Error: Could not retrieve jobs.';
        $data['error'] .= 'HTTP code: ' . $recipes['httpcode'];
        $data['error'] .= 'Response header: ' . $recipes['response_header'];
      }

    }

    return $data;
  }

  /**
   * Retrieve the server machine state
   *
   * @return array
   */
  public function machine_state() {

    $data = array();

    // /machine
    $params = array(
      'machine'
    );

    // API returns 200 and all job data for a successful GET,
    // and a 200 for an unsuccessful GET (for an invalid client ID).
    $data = $this->query_api($params, 'GET');

    return $data;
  }

  /**
   * See if a job or set of jobs are running.
   *
   * @param array $job_ids An array of job ids
   * @return bool
   */
  public function are_jobs_running($job_ids = array()) {

    $data = false;
    $client_jobs = array();

    if (!empty($job_ids)) {
      
      // Get the machine state.
      $state = $this->machine_state();

      if (!empty($state['result'])) {

        // Get the repository client's jobs.
        $json_decoded = json_decode($state['result'], true);

        foreach ($json_decoded['clients'] as $key => $value) {

          if ($value['name'] === 'Goran Halusa') {
            $client_jobs = $json_decoded['clients'][$key];
            break;
          }
        }

        // Check for job_ids in the repository client's runningJobs.
        if (!empty($client_jobs) && !empty($client_jobs['runningJobs'])) {
          foreach ($job_ids as $key => $value) {
            // If a running job is found, set $data to true and break.
            if (in_array($value, $client_jobs['runningJobs'])) {
              $data = true;
              break;
            }
          }
        }

      }
    }

    return $data;
  }

  /**
   * Get processing assets.
   *
   * @param array $job_ids An array of job ids
   * @param object $filesystem Filesystem object (via Flysystem).
   * See: https://flysystem.thephpleague.com/docs/usage/filesystem-api/
   * @return bool
   */
  public function get_processing_assets($job_ids = array(), $filesystem) {

    $data = array();
    $client_jobs = array();

    if (!empty($job_ids)) {
      
      // Loop through jobs, and retrieve outputted assets.
      foreach ($job_ids as $job_id) {

        // Retrieve a read-stream
        try {

          $files = $filesystem->listContents($job_id, false);

          if (!empty($files)) {

            foreach ($files as $file_key => $file_value) {

              // Only grab application/json and text/plain mimetypes.
              // TODO: transfer (pull) files to the repository for all other file types (e.g. .obj, .ply, or whatever).
              // And then, transfer to the file storage service (or leave them on the repository filesystem).
              if (($file_value['mimetype'] === 'text/plain; charset=utf-8') || ($file_value['mimetype'] === 'application/json; charset=utf-8')) {
                // Set the file path minus the protocol and host.
                $file_path = str_replace('http://si-3ddigip01.si.edu:8000/', '', $file_value['path']);
                // Set the file name
                $file_path_array = explode('/', $file_path);
                $file_name = array_pop($file_path_array);

                // Read the file and get the contents.
                // !!!WARNING!!!
                // Had to hack:
                // vendor/league/flysystem-webdav/src/WebDAVAdapter.php (lines 129-131)
                // vendor/league/flysystem/src/Filesystem.php (line 273)
                $stream = $filesystem->readStream($file_path);
                $contents = stream_get_contents($stream);
                // Before calling fclose on the resource, check if it’s still valid using is_resource.
                if (is_resource($stream)) fclose($stream);

                $data[] = array(
                  'job_id' => $job_id,
                  'file_name' => $file_name,
                  'file_contents' => $contents,
                );

              }

            }

          }

        }
        // Catch the error.
        catch(\League\Flysystem\FileNotFoundException | \Sabre\HTTP\ClientException $e) {
          throw $this->createNotFoundException($e->getMessage() . ' - The directory, ' . $job_id . ', does not exist.');
        }

      }

    }

    return $data;
  }

  /**
   * Query API
   *
   * @param array $params
   * @param bool $return_output
   * @param string $method
   * @param array $post_params
   * @param string $content_type
   * @return array
   */
  public function query_api($params = array(), $method = 'GET', $post_params = array(), $return_output = true, $content_type = 'Content-type: application/json; charset=utf-8')
  {
    // $this->u->dumper($method,0);
    // $this->u->dumper($params,0);
    // $this->u->dumper(json_encode($post_params),0);

    $data = array();

    // Make sure parameters are passed.
    if (is_array($params) && !empty($params)) {

      if (!function_exists('curl_init')){ 
        die('CURL is not installed!');
      }

      $url_path = implode('/', $params);
      $url_path = str_replace('.', '%2E', $url_path);

      // $this->u->dumper('cURL URL:',0);
      // $this->u->dumper($this->processing_service_location . $url_path);

      $ch = curl_init($this->processing_service_location . $url_path);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array($content_type));
      curl_setopt($ch, CURLINFO_HEADER_OUT, true);

      switch (strtoupper($method)) {
        case "POST":
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_params));
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
          break;
        case "PATCH":
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
          break;
        case "DELETE":
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
          break;
      }

      // Return output.
      if($return_output) $data['result'] = curl_exec($ch);
      // Suppress output.
      if(!$return_output) curl_exec($ch);
      // Return the HTTP code.
      $data['httpcode'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $data['response_header'] = curl_getinfo($ch, CURLINFO_HEADER_OUT);
      curl_close($ch);
    }

    return $data;
  }

  /**
   * Create GUID
   *
   * @return string
   */
  public function create_guid() {

    if (function_exists('com_create_guid')){
      return com_create_guid();
    } else {
      mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
      $charid = strtoupper(md5(uniqid(rand(), true)));
      $hyphen = chr(45);// "-"
      $uuid = substr($charid, 0, 8).$hyphen
          .substr($charid, 8, 4).$hyphen
          .substr($charid,12, 4).$hyphen
          .substr($charid,16, 4).$hyphen
          .substr($charid,20,12);
      return $uuid;
    }

  }

}