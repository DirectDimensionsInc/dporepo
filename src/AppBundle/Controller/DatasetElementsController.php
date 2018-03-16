<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\DBAL\Driver\Connection;

use PDO;

// Custom utility bundles
use AppBundle\Utils\AppUtilities;

// Projects methods
use AppBundle\Controller\ProjectsController;
// Subjects methods
use AppBundle\Controller\SubjectsController;
// Items methods
use AppBundle\Controller\ItemsController;
// Datasets methods
use AppBundle\Controller\DatasetsController;

use AppBundle\Form\DatasetElement;
use AppBundle\Entity\DatasetElements;

class DatasetElementsController extends Controller
{
    /**
     * @var object $u
     */
    public $u;

    /**
    * Constructor
    * @param object  $u  Utility functions object
    */
    public function __construct(AppUtilities $u)
    {
        // Usage: $this->u->dumper($variable);
        $this->u = $u;
    }

    /**
     * @Route("/admin/projects/dataset_elements/{projects_id}/{subjects_id}/{items_id}/{datasets_id}", name="dataset_elements_browse", methods="GET")
     */
    public function browse_dataset_elements(Connection $conn, Request $request, ProjectsController $projects, SubjectsController $subjects, ItemsController $items, DatasetsController $datasets)
    {
        // Database tables are only created if not present.
        $create_capture_dataset_elements_table = $this->create_capture_dataset_elements_table($conn);

        $projects_id = !empty($request->attributes->get('projects_id')) ? $request->attributes->get('projects_id') : false;
        $subjects_id = !empty($request->attributes->get('subjects_id')) ? $request->attributes->get('subjects_id') : false;
        $items_id = !empty($request->attributes->get('items_id')) ? $request->attributes->get('items_id') : false;
        $datasets_id = !empty($request->attributes->get('datasets_id')) ? $request->attributes->get('datasets_id') : false;

        // Check to see if the parent record exists/active, and if it doesn't, throw a createNotFoundException (404).
        $dataset_data = $datasets->get_dataset((int)$datasets_id, $conn);
        if(!$dataset_data) throw $this->createNotFoundException('The record does not exist');

        // dump($dataset_data);
        
        $project_data = $projects->get_project((int)$projects_id, $conn);
        $subject_data = $subjects->get_subject((int)$subjects_id, $conn);
        $item_data = $items->get_item((int)$items_id, $conn);
        $dataset_element_data = $this->get_dataset_element((int)$datasets_id, $conn);

        // Truncate the item_description.
        $more_indicator = (strlen($item_data['item_description']) > 50) ? '...' : '';
        $item_data['item_description_truncated'] = substr($item_data['item_description'], 0, 50) . $more_indicator;

        return $this->render('datasetElements/browse_dataset_elements.html.twig', array(
            'page_title' => 'Capture Dataset: ' .  $dataset_data['capture_dataset_name'],
            'projects_id' => $projects_id,
            'subjects_id' => $subjects_id,
            'items_id' => $items_id,
            'datasets_id' => $datasets_id,
            'project_data' => $project_data,
            'subject_data' => $subject_data,
            'item_data' => $item_data,
            'dataset_data' => $dataset_data,
            'dataset_element_data' => $dataset_element_data,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
        ));
    }

    /**
     * @Route("/admin/projects/datatables_browse_dataset_elements/{projects_id}/{subjects_id}/{items_id}/{datasets_id}", name="dataset_elements_browse_datatables", methods="POST")
     *
     * Browse dataset_elements
     *
     * Run a query to retreive all dataset elements in the database.
     *
     * @param   object  Connection  Database connection object
     * @param   object  Request     Request object
     * @return  array|bool          The query result
     */
    public function datatables_browse_dataset_elements(Connection $conn, Request $request)
    {
        $sort = '';
        $search_sql = '';
        $pdo_params = array();
        $data = array();

        $req = $request->request->all();
        $datasets_id = !empty($request->attributes->get('datasets_id')) ? $request->attributes->get('datasets_id') : false;

        $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
        $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
        $sort_order = $req['order'][0]['dir'];
        $start_record = !empty($req['start']) ? $req['start'] : 0;
        $stop_record = !empty($req['length']) ? $req['length'] : 20;
        $limit_sql = " LIMIT {$start_record}, {$stop_record} ";

        if (!empty($sort_field) && !empty($sort_order)) {
            $sort = " ORDER BY {$sort_field} {$sort_order}";
        } else {
            $sort = " ORDER BY capture_data_elements.last_modified DESC ";
        }

        if ($search) {
            $pdo_params[] = '%'.$search.'%';
            $pdo_params[] = '%'.$search.'%';
            $search_sql = "
                AND (
                  capture_data_elements.dataset_element_guid LIKE ?
                  OR capture_data_elements.camera_id LIKE ?
                ) ";
        }

        $statement = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS
                capture_data_elements.capture_data_elements_id AS manage
                ,capture_data_elements.capture_datasets_id
                ,capture_data_elements.capture_device_configuration_id
                ,capture_data_elements.capture_device_field_id
                ,capture_data_elements.capture_sequence_number
                ,capture_data_elements.cluster_position_field_id
                ,capture_data_elements.position_in_cluster_field_id
                ,capture_data_elements.date_created
                ,capture_data_elements.created_by_user_account_id
                ,capture_data_elements.last_modified
                ,capture_data_elements.last_modified_user_account_id
                ,capture_data_elements.capture_data_elements_id AS DT_RowId
            FROM capture_data_elements
            WHERE capture_data_elements.active = 1
            AND capture_datasets_id = " . (int)$datasets_id . "
            {$search_sql}
            {$sort}
            {$limit_sql}");
        $statement->execute($pdo_params);
        $data['aaData'] = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $conn->prepare("SELECT FOUND_ROWS()");
        $statement->execute();
        $count = $statement->fetch();
        $data["iTotalRecords"] = $count["FOUND_ROWS()"];
        $data["iTotalDisplayRecords"] = $count["FOUND_ROWS()"];
        
        return $this->json($data);
    }

    /**
     * Matches /admin/projects/dataset_element/*
     *
     * @Route("/admin/projects/dataset_element/{projects_id}/{subjects_id}/{items_id}/{datasets_id}/{dataset_elements_id}", name="dataset_elements_manage", methods={"GET","POST"}, defaults={"dataset_elements_id" = null})
     *
     * @param   object  Connection    Database connection object
     * @param   object  Request       Request object
     * @return  array|bool            The query result
     */
    function show_dataset_elements_form( Connection $conn, Request $request, ProjectsController $projects, SubjectsController $subjects, ItemsController $items, DatasetsController $datasets )
    {
        $dataset_element = new DatasetElements();
        $post = $request->request->all();
        $dataset_elements_id = !empty($request->attributes->get('dataset_elements_id')) ? $request->attributes->get('dataset_elements_id') : false;
        
        // Retrieve data from the database.
        $dataset_element = (!empty($dataset_elements_id) && empty($post)) ? $dataset_element->getDatasetElement((int)$dataset_elements_id, $conn) : $dataset_element;

        $dataset_element->projects_id = !empty($request->attributes->get('projects_id')) ? $request->attributes->get('projects_id') : false;
        $dataset_element->subjects_id = !empty($request->attributes->get('subjects_id')) ? $request->attributes->get('subjects_id') : false;
        $dataset_element->items_id = !empty($request->attributes->get('items_id')) ? $request->attributes->get('items_id') : false;
        $dataset_element->datasets_id = !empty($request->attributes->get('datasets_id')) ? $request->attributes->get('datasets_id') : false;
        
        // Get data for the breadcumbs.
        // TODO: find a better way?
        $project_data = $projects->get_project((int)$dataset_element->projects_id, $conn);
        $subject_data = $subjects->get_subject((int)$dataset_element->subjects_id, $conn);
        $item_data = $items->get_item((int)$dataset_element->items_id, $conn);
        $dataset_data = $datasets->get_dataset((int)$dataset_element->datasets_id, $conn);
        
        // Truncate the item_description so the breadcrumb don't blow up.
        $more_indicator = (strlen($item_data['item_description']) > 50) ? '...' : '';
        $item_data['item_description_truncated'] = substr($item_data['item_description'], 0, 50) . $more_indicator;

        // Get data from lookup tables.
        $dataset_element->calibration_object_type_options = $this->get_calibration_object_types($conn);

        // Create the form
        $form = $this->createForm(DatasetElement::class, $dataset_element);
        // Handle the request
        $form->handleRequest($request);

        // If form is submitted and passes validation, insert/update the database record.
        if ($form->isSubmitted() && $form->isValid()) {

            $dataset_element = $form->getData();
            $dataset_elements_id = $this->insert_update_dataset_elements($dataset_element, $dataset_element->datasets_id, $dataset_elements_id, $conn);

            $this->addFlash('message', 'Dataset Element successfully updated.');
            return $this->redirectToRoute('dataset_elements_browse', array('projects_id' => $dataset_element->projects_id, 'subjects_id' => $dataset_element->subjects_id, 'items_id' => $dataset_element->items_id, 'datasets_id' => $dataset_element->datasets_id));

        }

        return $this->render('datasetElements/dataset_element_form.html.twig', array(
            'page_title' => ((int)$dataset_elements_id && isset($dataset_element->capture_sequence_number)) ? 'Dataset Element: ' . $dataset_element->capture_sequence_number : 'Add a Dataset Element',
            'projects_id' => $dataset_element->projects_id,
            'subjects_id' => $dataset_element->subjects_id,
            'items_id' => $dataset_element->items_id,
            'datasets_id' => $dataset_element->datasets_id,
            'project_data' => $project_data,
            'subject_data' => $subject_data,
            'item_data' => $item_data,
            'dataset_data' => $dataset_data,
            'dataset_element_data' => $dataset_element,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
            'form' => $form->createView(),
        ));

    }

    /**
     * Insert/Update capture_data_elements
     *
     * Run queries to insert and update a capture_data_element in the database.
     *
     * @param   array   $data                 The data array
     * @param   int     $capture_datasets_id          The dataset ID
     * @param   int     $capture_data_elements_id  The dataset elements ID
     * @param   object  $conn                 Database connection object
     * @return  int     The item ID
     */
    public function insert_update_dataset_elements($data, $capture_datasets_id = FALSE, $capture_data_elements_id = FALSE, $conn)
    {

        // Update
        if($capture_data_elements_id) {
            $statement = $conn->prepare("
                UPDATE capture_data_elements
                SET capture_device_configuration_id = :capture_device_configuration_id
                ,capture_device_field_id = :capture_device_field_id
                ,capture_sequence_number = :capture_sequence_number
                ,cluster_position_field_id = :cluster_position_field_id
                ,position_in_cluster_field_id = :position_in_cluster_field_id
                ,last_modified_user_account_id = :last_modified_user_account_id
                WHERE capture_data_elements_id = :capture_data_elements_id
            ");
            $statement->bindValue(":capture_device_configuration_id", $data->capture_device_configuration_id, PDO::PARAM_INT);
            $statement->bindValue(":capture_device_field_id", $data->capture_device_field_id, PDO::PARAM_INT);
            $statement->bindValue(":capture_sequence_number", $data->capture_sequence_number, PDO::PARAM_INT);
            $statement->bindValue(":cluster_position_field_id", $data->cluster_position_field_id, PDO::PARAM_INT);
            $statement->bindValue(":position_in_cluster_field_id", $data->position_in_cluster_field_id, PDO::PARAM_INT);
            $statement->bindValue(":last_modified_user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->bindValue(":capture_data_elements_id", $capture_data_elements_id, PDO::PARAM_INT);
            $statement->execute();

            return $capture_data_elements_id;
        }

        // Insert
        if(!$capture_data_elements_id) {
            $statement = $conn->prepare("INSERT INTO capture_data_elements
                (capture_datasets_id, capture_device_configuration_id, capture_device_field_id, 
                capture_sequence_number, cluster_position_field_id, position_in_cluster_field_id,  
                date_created, created_by_user_account_id, last_modified_user_account_id )
                VALUES (:capture_datasets_id, :capture_device_configuration_id, :capture_device_field_id, 
                :capture_sequence_number, :cluster_position_field_id, :position_in_cluster_field_id, 
                NOW(), :user_account_id, :user_account_id )");
            $statement->bindValue(":capture_datasets_id", $capture_datasets_id, PDO::PARAM_INT);
            $statement->bindValue(":capture_device_configuration_id", $data->capture_device_configuration_id, PDO::PARAM_INT);
            $statement->bindValue(":capture_device_field_id", $data->capture_device_field_id, PDO::PARAM_INT);
            $statement->bindValue(":capture_sequence_number", $data->capture_sequence_number, PDO::PARAM_INT);
            $statement->bindValue(":cluster_position_field_id", $data->cluster_position_field_id, PDO::PARAM_INT);
            $statement->bindValue(":position_in_cluster_field_id", $data->position_in_cluster_field_id, PDO::PARAM_INT);
            $statement->bindValue(":user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->execute();
            $last_inserted_id = $conn->lastInsertId();

            if(!$last_inserted_id) {
                die('INSERT INTO `capture_data_elements` failed.');
            }

            return $last_inserted_id;
        }

    }

    /**
     * Get Dataset Elements
     *
     * Get dataset elements from the database.
     *
     * @param       int $datasets_id  The dataset ID
     * @return      array|bool        The query result
     */
    public function get_dataset_elements($capture_datasets_id = false, $conn)
    {
        $statement = $conn->prepare("
            SELECT
                projects.projects_id,
                subjects.subjects_id,
                items.items_id,
                capture_data_elements.capture_data_elements_id,
                capture_data_elements.capture_datasets_id,
                capture_data_elements.capture_device_configuration_id,
                capture_data_elements.capture_device_field_id,
                capture_data_elements.capture_sequence_number,
                capture_data_elements.cluster_position_field_id,
                capture_data_elements.position_in_cluster_field_id,
                capture_data_elements.date_created,
                capture_data_elements.created_by_user_account_id,
                capture_data_elements.last_modified,
                capture_data_elements.last_modified_user_account_id,
                capture_data_elements.active
            FROM capture_data_elements
            LEFT JOIN capture_datasets ON capture_datasets.capture_datasets_id = capture_data_elements.capture_datasets_id
            LEFT JOIN items ON items.items_id = capture_datasets.parent_item_repository_id
            LEFT JOIN subjects ON subjects.subjects_id = items.subjects_id
            LEFT JOIN projects ON projects.projects_id = subjects.projects_id
            WHERE capture_data_elements.active = 1
            AND capture_data_elements.capture_datasets_id = :capture_datasets_id");
        $statement->bindValue(":capture_datasets_id", $capture_datasets_id, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Dataset Elements (for the tree browser)
     *
     * @Route("/admin/projects/get_dataset_elements/{capture_datasets_id}", name="get_dataset_elements_tree_browser", methods="GET")
     */
    public function get_dataset_elements_tree_browser(Connection $conn, Request $request)
    {      
        $capture_datasets_id = !empty($request->attributes->get('capture_datasets_id')) ? $request->attributes->get('capture_datasets_id') : false;        
        $capture_data_elements = $this->get_dataset_elements($capture_datasets_id, $conn);

        foreach ($capture_data_elements as $key => $value) {
            $data[$key] = array(
                'id' => 'datasetElementId-' . $value['capture_data_elements_id'],
                'children' => false,
                'text' => $value['capture_sequence_number'],
                'a_attr' => array('href' => '/admin/projects/dataset_element/' . $value['projects_id'] . '/' . $value['subjects_id'] . '/' . $value['items_id'] . '/' . $value['capture_datasets_id'] . '/' . $value['capture_data_elements_id']),
            );
        }

        $response = new JsonResponse($data);
        return $response;
    }

    /**
     * Get Dataset Element
     *
     * Get one dataset element from the database.
     *
     * @param       int $dataset_elements_id  The dataset element ID
     * @return      array|bool                The query result
     */
    public function get_dataset_element($dataset_elements_id = false, $conn)
    {
        $statement = $conn->prepare("SELECT *
            FROM dataset_elements
            WHERE dataset_elements_id = :dataset_elements_id");
        $statement->bindValue(":dataset_elements_id", $dataset_elements_id, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get calibration_object_types
     * @return  array|bool  The query result
     */
    public function get_calibration_object_types($conn)
    {
        $data = array();

        $statement = $conn->prepare("SELECT * FROM calibration_object_types ORDER BY label ASC");
        $statement->execute();
        
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $key => $value) {
            $label = $this->u->removeUnderscoresTitleCase($value['label']);
            $data[$label] = $value['calibration_object_types_id'];
        }

        return $data;
    }

    /**
     * Delete Multiple Dataset Elements
     *
     * @Route("/admin/projects/dataset_elements/{projects_id}/{subjects_id}/{items_id}/{datasets_id}/delete", name="dataset_elements_remove_records", methods={"GET"})
     * Run a query to delete multiple records.
     *
     * @param   int     $ids      The record ids
     * @param   object  $conn     Database connection object
     * @param   object  $request  Request object
     * @return  void
     */
    public function delete_multiple_datasets(Connection $conn, Request $request)
    {
        $ids = $request->query->get('ids');
        $projects_id = !empty($request->attributes->get('projects_id')) ? $request->attributes->get('projects_id') : false;
        $subjects_id = !empty($request->attributes->get('subjects_id')) ? $request->attributes->get('subjects_id') : false;
        $items_id = !empty($request->attributes->get('items_id')) ? $request->attributes->get('items_id') : false;
        $datasets_id = !empty($request->attributes->get('datasets_id')) ? $request->attributes->get('datasets_id') : false;

        if(!empty($ids) && $projects_id && $subjects_id && $items_id && $datasets_id) {

          $ids_array = explode(',', $ids);

          foreach ($ids_array as $key => $id) {

            $statement = $conn->prepare("
                UPDATE dataset_elements
                SET active = 0, last_modified_user_account_id = :last_modified_user_account_id
                WHERE dataset_elements_id = :id
            ");
            $statement->bindValue(":id", $id, PDO::PARAM_INT);
            $statement->bindValue(":last_modified_user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->execute();

          }

          $this->addFlash('message', 'Records successfully removed.');

        } else {
          $this->addFlash('message', 'Missing data. No records removed.');
        }

        return $this->redirectToRoute('dataset_elements_browse', array('projects_id' => $projects_id, 'subjects_id' => $subjects_id, 'items_id' => $items_id, 'datasets_id' => $datasets_id));
    }

    /**
     * Delete dataset element
     *
     * Run a query to delete a dataset element from the database.
     *
     * @param   int     $dataset_elements_id  The dataset element ID
     * @param   object  $conn                 Database connection object
     * @return  void
     */
    public function delete_dataset_element($dataset_elements_id, $conn)
    {
        $statement = $conn->prepare("
            DELETE FROM dataset_elements
            WHERE dataset_elements_id = :dataset_elements_id");
        $statement->bindValue(":dataset_elements_id", $dataset_elements_id, PDO::PARAM_INT);
        $statement->execute();
    }

  /**
   * Create Dataset Elements Table
   *
   * @return      void
   */
  public function create_capture_dataset_elements_table($conn)
  {
    $statement = $conn->prepare("CREATE TABLE IF NOT EXISTS `capture_data_elements` (
      `capture_data_elements_id` int(11) NOT NULL AUTO_INCREMENT,
      `capture_datasets_id` int(11) NOT NULL,
      `capture_device_configuration_id` varchar(255) DEFAULT '',
      `capture_device_field_id` int(11) DEFAULT NULL,
      `capture_sequence_number` int(11) DEFAULT NULL,
      `cluster_position_field_id` int(11) DEFAULT NULL,
      `position_in_cluster_field_id` int(11) DEFAULT NULL,
      `date_created` datetime NOT NULL,
      `created_by_user_account_id` int(11) NOT NULL,
      `last_modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `last_modified_user_account_id` int(11) NOT NULL,
      `active` tinyint(1) NOT NULL DEFAULT '1',
      PRIMARY KEY (`capture_data_elements_id`),
      KEY `created_by_user_account_id` (`created_by_user_account_id`),
      KEY `last_modified_user_account_id` (`last_modified_user_account_id`),
      KEY `dataset_element_guid` (`capture_device_configuration_id`)
    ) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8 COMMENT='This table stores capture_data_elements metadata'");
    $statement->execute();
    $error = $conn->errorInfo();

    if ($error[0] !== '00000') {
        var_dump($conn->errorInfo());
        die('CREATE TABLE `capture_data_elements` failed.');
    } else {
      return TRUE;
    }

  }

}
