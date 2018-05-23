<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\DBAL\Driver\Connection;

use AppBundle\Controller\RepoStorageHybridController;
use Symfony\Component\DependencyInjection\Container;
use PDO;

use AppBundle\Form\Item;
use AppBundle\Entity\Items;

// Custom utility bundle
use AppBundle\Utils\AppUtilities;

class ItemsController extends Controller
{
    /**
     * @var object $u
     */
    public $u;
    private $repo_storage_controller;
    /**
     * @var string
     */
    private $file_upload_path;
    /**
     * @var string
     */
    private $file_processing_path;

    /**
     * Constructor
     * @param object  $u  Utility functions object
     */
    public function __construct(AppUtilities $u, $file_upload_path, $file_processing_path)
    {
      // Usage: $this->u->dumper($variable);
      $this->u = $u;
      $this->repo_storage_controller = new RepoStorageHybridController();

      // Establish paths.
      $this->file_upload_path = $file_upload_path;
      $this->file_processing_path = $file_processing_path;
    }

    /**
     * @Route("/admin/projects/items/{project_repository_id}/{subject_repository_id}", name="items_browse", methods="GET")
     */
    public function browse_items(Connection $conn, Request $request, ProjectsController $projects, SubjectsController $subjects)
    {
        $this->repo_storage_controller->setContainer($this->container);

        // Database tables are only created if not present.
        $ret = $this->repo_storage_controller->build('createTable', array('table_name' => 'item'));

        $project_repository_id = !empty($request->attributes->get('project_repository_id')) ? $request->attributes->get('project_repository_id') : false;
        $subject_repository_id = !empty($request->attributes->get('subject_repository_id')) ? $request->attributes->get('subject_repository_id') : false;

        // Check to see if the parent record exists/active, and if it doesn't, throw a createNotFoundException (404).
        $subject_data = $subjects->get_subject($this->container, (int)$subject_repository_id);
        if(!$subject_data) throw $this->createNotFoundException('The record does not exist');

        $project_data = $this->repo_storage_controller->execute('getProject', array('project_repository_id' => (int)$project_repository_id));

        return $this->render('items/browse_items.html.twig', array(
            'page_title' => 'Subject: ' .  $subject_data['subject_name'],
            'project_repository_id' => $project_repository_id,
            'subject_repository_id' => $subject_repository_id,
            'subject_data' => $subject_data,
            'project_data' => $project_data,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
        ));
    }

    /**
     * @Route("/admin/projects/datatables_browse_items/{project_repository_id}/{subject_repository_id}", name="items_browse_datatables", methods="POST")
     *
     * Browse items
     *
     * Run a query to retrieve all items in the database.
     *
     * @param   object  Request     Request object
     * @return  array|bool          The query result
     */
    public function datatables_browse_items(Request $request)
    {
        $req = $request->request->all();
        $subject_repository_id = !empty($request->attributes->get('subject_repository_id')) ? $request->attributes->get('subject_repository_id') : false;

        // First, perform a 3D model generation status check, and update statuses in the database accordingly.
        $this->repo_storage_controller->setContainer($this->container);
        $results = $this->repo_storage_controller->execute('getItemGuidsBySubjectId', array(
            'subject_repository_id' => (int)$subject_repository_id,
          )
        );

        if(!empty($results)) {
            foreach ($results as $key => $value) {
              // Set 3D model generation statuses.
              //@todo
              // An item may have 1 or more of either or both capture_datasets and models.
              // Processing status may exist for each model, for each capture_dataset.
              // How should we show those individual statuses rolled up as one status for the item?
              // $this->getDirectoryStatuses($this->container, $value['item_guid']);
            }
        }

        $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
        $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
        $sort_order = $req['order'][0]['dir'];
        $start_record = !empty($req['start']) ? $req['start'] : 0;
        $stop_record = !empty($req['length']) ? $req['length'] : 20;

        $query_params = array(
          'record_type' => 'item',
          'sort_field' => $sort_field,
          'sort_order' => $sort_order,
          'start_record' => $start_record,
          'stop_record' => $stop_record,
          'subject_repository_id' => $subject_repository_id,
        );
        if ($search) {
          $query_params['search_value'] = $search;
        }

        $data = $this->repo_storage_controller->execute('getDatatableItem', $query_params);

        return $this->json($data);
    }

    /**
     * Matches /admin/projects/item/*
     *
     * @Route("/admin/projects/item/{project_repository_id}/{subject_repository_id}/{item_repository_id}", name="items_manage", methods={"GET","POST"}, defaults={"item_repository_id" = null})
     *
     * @param   object  Connection  Database connection object
     * @param   object  Request     Request object
     * @return  array|bool          The query result
     */
    function show_items_form( Connection $conn, Request $request )
    {
        $item = new Items();
        $post = $request->request->all();
        $id = !empty($request->attributes->get('item_repository_id')) ? $request->attributes->get('item_repository_id') : false;
        $item->project_repository_id = !empty($request->attributes->get('project_repository_id')) ? $request->attributes->get('project_repository_id') : false;
        $item->subject_repository_id = !empty($request->attributes->get('subject_repository_id')) ? $request->attributes->get('subject_repository_id') : false;

        $this->repo_storage_controller->setContainer($this->container);

        // Retrieve data from the database.
        if (!empty($id) && empty($post)) {
          $item_array = $this->repo_storage_controller->execute('getItem', array(
            'item_repository_id' => $id,
          ));
          if(is_array($item_array)) {
            $item = (object)$item_array;
          }
        }

        // Get data from lookup tables.
        $item->item_type_lookup_options = $this->get_item_types($this->container);

        // Create the form
        $form = $this->createForm(Item::class, $item);
        // Handle the request
        $form->handleRequest($request);

        // If form is submitted and passes validation, insert/update the database record.
        if ($form->isSubmitted() && $form->isValid()) {

            $item = $form->getData();
            $id = $this->repo_storage_controller->execute('saveRecord', array(
              'base_table' => 'item',
              'record_id' => $id,
              'user_id' => $this->getUser()->getId(),
              'values' => (array)$item
            ));
            //$item_repository_id = $this->insert_update_item($item, $item->subject_repository_id, $item_repository_id, $conn);

            $this->addFlash('message', 'Item successfully updated.');
            return $this->redirect('/admin/projects/datasets/' . $item->project_repository_id . '/' . $item->subject_repository_id . '/' . $id);

        }

        return $this->render('items/item_form.html.twig', array(
            'page_title' => ((int)$id && isset($item->item_display_name)) ? 'Item: ' . $item->item_display_name : 'Add Item',
            'item_data' => $item,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
            'form' => $form->createView(),
        ));

    }

    /**
     * Get Item
     *
     * Run a query to retrieve one item from the database.
     *
     * @param   int $item_id   The subject ID
     * @return  array|bool     The query result
     */
    public function get_item($container, $item_id)
    {
        $this->repo_storage_controller->setContainer($container);
        $data = $this->repo_storage_controller->execute('getItem', array(
            'item_repository_id' => $item_id,
          )
        );
        return $data;
    }

    /**
     * Get Items
     *
     * Run a query to retrieve all items from the database.
     *
     * @param   $container  Symfony container, passed from caller
     * @param   int $subject_repository_id  The subject ID
     * @return  array|bool     The query result
     */
    public function get_items($container, $subject_repository_id = false)
    {

        $this->repo_storage_controller->setContainer($container);
        $items_data = $this->repo_storage_controller->execute('getItemsBySubjectId',
          array(
            'subject_repository_id' => $subject_repository_id,
          )
        );
        return $items_data;
    }

    /**
     * Get Items (for the tree browser)
     *
     * @Route("/admin/projects/get_items/{subject_repository_id}", name="get_items_tree_browser", methods="GET")
     */
    public function get_items_tree_browser(Request $request, DatasetsController $datasets)
    {      
        $subject_repository_id = !empty($request->attributes->get('subject_repository_id')) ? $request->attributes->get('subject_repository_id') : false;
        $items = $this->get_items($this->container, $subject_repository_id);

        foreach ($items as $key => $value) {
            // Check for child dataset records so the 'children' key can be set accordingly.
            $dataset_data = $datasets->get_datasets($this->container, (int)$value['item_repository_id']);
            $data[$key] = array(
                'id' => 'itemId-' . $value['item_repository_id'],
                'children' => count($dataset_data) ? true : false,
                'text' => $value['item_display_name'],
                'a_attr' => array('href' => '/admin/projects/datasets/' . $value['project_repository_id'] . '/' . $value['subject_repository_id'] . '/' . $value['item_repository_id']),
            );
        }

        $response = new JsonResponse($data);
        return $response;
    }

    /**
     * Get item_types
     * @return  array|bool  The query result
     */
    public function get_item_types($container)
    {
        $data = array();
        $this->repo_storage_controller->setContainer($container);
        $temp = $this->repo_storage_controller->execute('getRecords', array(
            'base_table' => 'item_type',
            'fields' => array(),
            'sort_fields' => array(
              0 => array('field_name' => 'label')
            ),
          )
        );

        foreach ($temp as $key => $value) {
            // $label = $this->u->removeUnderscoresTitleCase($value['label']);
            $label = $value['label'];
            $data[$label] = $value['item_type_repository_id'];
        }

        return $data;
    }

    /**
     * Delete Multiple Items
     *
     * @Route("/admin/projects/items/{project_repository_id}/{subject_repository_id}/delete", name="items_remove_records", methods={"GET"})
     * Run a query to delete multiple records.
     *
     * @param   int     $ids      The record ids
     * @param   object  $request  Request object
     * @return  void
     */
    public function delete_multiple_items(Request $request)
    {
        $ids = $request->query->get('ids');
        $project_repository_id = !empty($request->attributes->get('project_repository_id')) ? $request->attributes->get('project_repository_id') : false;
        $subject_repository_id = !empty($request->attributes->get('subject_repository_id')) ? $request->attributes->get('subject_repository_id') : false;

        if(!empty($ids) && $project_repository_id && $subject_repository_id) {

          $ids_array = explode(',', $ids);

          $this->repo_storage_controller->setContainer($this->container);

          foreach ($ids_array as $key => $id) {
            $ret = $this->repo_storage_controller->execute('markItemInactive', array(
              'record_id' => $id,
              'user_id' => $this->getUser()->getId(),
            ));
          }

          $this->addFlash('message', 'Records successfully removed.');

        } else {
          $this->addFlash('message', 'Missing data. No records removed.');
        }

        return $this->redirectToRoute('items_browse', array('project_repository_id' => $project_repository_id, 'subject_repository_id' => $subject_repository_id));
    }

    /**
     * Directory Names
     *
     * @return array  An array of the target directory names.
     */
    public function directoryNames() {
        return array(
            'jobbox',
            'jobboxprocess',
            'clipped',
            'realitycapture',
            'instantuv'
        );
    }

    /**
     * Update Statuses
     *
     * @param  bool  $itemguid  The item guid
     * @param  bool  $statusid  The status id
     * @param  object  $conn    Database connection object
     * @return bool
     */
    public function updateStatus($container, $itemguid = FALSE, $statusid = 0) {

        $updated = FALSE;
        if(!empty($itemguid)) {
            $this->repo_storage_controller->setContainer($container);
            $ret = $this->repo_storage_controller->execute('markCaptureDatasetInactive', array(
              'item_guid' => $itemguid,
              'status_type_id' => $statusid,
            ));
            $updated = TRUE;
        }

        return $updated;
    }

    /**
     * @Route("/admin/projects/create_directory_in_jobbox/{item_guid}/{destination}", name="create_directory_in_jobbox", methods={"GET"}, defaults={"item_guid" = false, "destination" = false})
     *
     * Create Direcory in JobBox
     *
     * @param   object  Request  Request object
     * @return  void
     */
    function create_directory_in_jobbox(Request $request)
    {
      //@todo revisit this and browse_datasets.html.twig that references this route.
      // Move to Workflow Service.
        $data = false;
        $directoryContents = array();
        $itemguid = !empty($request->attributes->get('item_guid')) ? $request->attributes->get('item_guid') : false;
        $destination = !empty($request->attributes->get('destination')) ? $request->attributes->get('destination') : false;
        $ids = explode('|', $destination);
        $message = 'The directory could not be created (' . $itemguid . '). If this persists, please contact the administrator.';
 
        if($itemguid && !empty($itemguid)) {

            $directoryContents = is_dir($this->file_upload_path) ? scandir($this->file_upload_path) : array();

            if(!in_array($itemguid, $directoryContents)) {
                // Create the directory.
                mkdir($this->file_upload_path . '/' . $itemguid, 0775);
                // Check to see if the directory was created.
                if(is_dir($this->file_upload_path . '/' . $itemguid)) {
                    $message = 'The directory has been created (' . $itemguid . ').';
                }
                
            } else {
                // The directory already exists, so don't overwrite it. Just send a mesaage.
                $message = 'The directory already exists (' . $itemguid . ').';
            }

        }

        // If the endpoint is accessed from within the application, redirect to the destination.
        // If there is no destination, return a message in JSON format.
        if($destination) {
            $this->addFlash('message', $message);
            return $this->redirectToRoute('datasets_browse', array('project_repository_id' => $ids[0], 'subject_repository_id' => $ids[1], 'item_repository_id' => $ids[2]));
        } else {
            return $this->json(array('message' => $message));
        }
    }

}
