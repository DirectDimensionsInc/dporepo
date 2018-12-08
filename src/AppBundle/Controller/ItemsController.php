<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\DBAL\Driver\Connection;

use AppBundle\Controller\RepoStorageHybridController;
use PDO;

use AppBundle\Form\Item;
use AppBundle\Entity\Items;

// Custom utility bundle
use AppBundle\Utils\AppUtilities;
use AppBundle\Service\RepoUserAccess;

class ItemsController extends Controller
{
    /**
     * @var object $u
     */
    public $u;
    private $repo_storage_controller;
    private $repo_user_access;

    /**
     * Constructor
     * @param object  $u  Utility functions object
     */
    public function __construct(AppUtilities $u, Connection $conn)
    {
      // Usage: $this->u->dumper($variable);
      $this->u = $u;
      $this->repo_storage_controller = new RepoStorageHybridController($conn);
        $this->repo_user_access = new RepoUserAccess($conn);
    }

    /**
     * @Route("/admin/projects/items/{project_id}/{subject_id}", name="items_browse", methods="GET")
     */
    public function browse_items(Connection $conn, Request $request, ProjectsController $projects, SubjectsController $subjects)
    {

        $project_id = !empty($request->attributes->get('project_id')) ? $request->attributes->get('project_id') : false;
        $subject_id = !empty($request->attributes->get('subject_id')) ? $request->attributes->get('subject_id') : false;

        // Check to see if the parent record exists/active, and if it doesn't, throw a createNotFoundException (404).
        $subject_data = $subjects->get_subject((int)$subject_id);
        if(!$subject_data) throw $this->createNotFoundException('The record does not exist');

        $project_data = $this->repo_storage_controller->execute('getProject', array('project_id' => (int)$project_id));

        return $this->render('items/browse_items.html.twig', array(
            'page_title' => 'Subject: ' .  $subject_data['subject_name'],
            'project_id' => $project_id,
            'subject_id' => $subject_id,
            'subject_data' => $subject_data,
            'project_data' => $project_data,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
        ));
    }

    /**
     * @Route("/admin/projects/datatables_browse_items/{project_id}/{subject_id}", name="items_browse_datatables", methods="POST", defaults={"project_id" = null, "subject_id" = null})
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
        $subject_id = !empty($request->attributes->get('subject_id')) ? $request->attributes->get('subject_id') : false;

        // First, perform a 3D model generation status check, and update statuses in the database accordingly.
        $results = $this->repo_storage_controller->execute('getItemGuidsBySubjectId', array(
            'subject_id' => (int)$subject_id,
          )
        );

        if(!empty($results)) {
            foreach ($results as $key => $value) {
              // Set 3D model generation statuses.
              //@todo
              // An item may have 1 or more of either or both capture_datasets and models.
              // Processing status may exist for each model, for each capture_dataset.
              // How should we show those individual statuses rolled up as one status for the item?
              // $this->getDirectoryStatuses($value['item_guid']);
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
          'subject_id' => $subject_id,
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
     * @Route("/admin/projects/item/{project_id}/{subject_id}/{item_id}", name="items_manage", methods={"GET","POST"}, defaults={"item_id" = null})
     *
     * @param   object  Connection  Database connection object
     * @param   object  Request     Request object
     * @return  array|bool          The query result
     */
    function show_items_form( Connection $conn, Request $request )
    {
        $item = new Items();
        $item->access_model_purpose = NULL;
        $item->inherit_publication_default = '';

        $post = $request->request->all();
        $item->project_id = !empty($request->attributes->get('project_id')) ? $request->attributes->get('project_id') : false;
        $item->subject_id = !empty($request->attributes->get('subject_id')) ? $request->attributes->get('subject_id') : false;
        $id = false;
        $ajax = false;

        if (!empty($request->attributes->get('item_id'))) {
          if ($request->attributes->get('item_id') !== 'ajax') {
            $id = $request->attributes->get('item_id');
          }
          else {
            $ajax = true;
          }
        }

        // Retrieve data from the database.
        if (!empty($id) && empty($post)) {
          $item_array = $this->repo_storage_controller->execute('getItem', array(
            'item_id' => $id,
          ));
          if(is_array($item_array)) {
            $item = (object)$item_array;
          }

          $item->api_publication_picker = NULL;
          $picker_val = (string)$item->api_published;
          $picker_val .= (string)$item->api_discoverable;
          $item->api_publication_picker = $picker_val;

          $tmp = '';
          if(NULL == $item->inherit_api_published) {
            $tmp = "Publication not set, ";
          }
          elseif($item->inherit_api_published == 1) {
            $tmp = "Published, ";
          }
          elseif($item->inherit_api_published == 0) {
            $tmp = "Not Published, ";
          }
          if(NULL == $item->inherit_api_discoverable) {
            $tmp .= "Discoverable not set";
          }
          elseif($item->inherit_api_discoverable == 1) {
            $tmp .= "Discoverable";
          }
          elseif($item->inherit_api_discoverable == 0) {
            $tmp .= "Not Discoverable";
          }
          $item->inherit_publication_default = $tmp;

          $item->model_purpose_picker = $item->api_access_model_purpose;
        }

        // Get data from lookup tables.
        $item->item_type_lookup_options = $this->get_item_types();
        $item->api_publication_options = array(
          'Published, Discoverable' => '11',
          'Published, Not Discoverable' => '10',
          'Not Published' => '00',
        );
        $model_purpose_options = $this->repo_storage_controller->execute('getDataForLookup', array(
          'table_name' => 'model_purpose',
          'value_field' => 'model_purpose_description',
          'id_field' => 'model_purpose_id',
        ));
        $model_face_count_options = $this->repo_storage_controller->execute('getDataForLookup', array(
          'table_name' => 'model_face_count',
          'value_field' => 'model_face_count',
          'id_field' => 'model_face_count_id',
        ));
        $uv_map_size_options = $this->repo_storage_controller->execute('getDataForLookup', array(
          'table_name' => 'uv_map_size',
          'value_field' => 'uv_map_size',
          'id_field' => 'uv_map_size_id',
        ));

        $item->model_face_count_options = $model_face_count_options;
        $item->uv_map_size_options = $uv_map_size_options;
        $item->model_purpose_options = $model_purpose_options;


        // Create the form
        $form = $this->createForm(Item::class, $item);
        // Handle the request
        $form->handleRequest($request);

        // If form is submitted and passes validation, insert/update the database record.
        if ($form->isSubmitted() && $form->isValid()) {

            $item = $form->getData();

            if(isset($item->api_publication_picker)) {
              if($item->api_publication_picker == '11') {
                $item->api_published = 1;
                $item->api_discoverable = 1;
              }
              elseif($item->api_publication_picker == '10') {
                $item->api_published = 1;
                $item->api_discoverable = 0;
              }
              elseif($item->api_publication_picker == '00') {
                $item->api_published = 0;
                $item->api_discoverable = 0;
              }
            }
            else {
              $item->api_published = NULL;
              $item->api_discoverable = NULL;
            }

            $id = $this->repo_storage_controller->execute('saveItem', array(
              'base_table' => 'item',
              'record_id' => $id,
              'user_id' => $this->getUser()->getId(),
              'values' => (array)$item
            ));

            if ($ajax) {
              // Return the ID of the new record.
              $response = new JsonResponse(array('id' => $id));
              return $response;
            } else {
                $this->addFlash('message', 'Item successfully updated.');
                return $this->redirect('/admin/projects/datasets/' . $item->project_id . '/' . $item->subject_id . '/' . $id);
            }
        }

        // Truncate the item_description.
        $more_indicator = (strlen($item->item_description) > 50) ? '...' : '';
        $item->item_description_truncated = substr($item->item_description, 0, 50) . $more_indicator;

        if ($ajax) {
          $response = new JsonResponse($item);
          return $response;
        } else {
            return $this->render('items/item_form.html.twig', array(
                'page_title' => ((int)$id && isset($item->item_description_truncated)) ? 'Item: ' . $item->item_description_truncated : 'Add Item',
                'item_data' => $item,
                'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
                'form' => $form->createView(),
            ));
        }

    }

    /**
     * Get Item
     *
     * Run a query to retrieve one item from the database.
     *
     * @param   int $item_id   The subject ID
     * @return  array|bool     The query result
     */
    public function get_item($item_id)
    {
        $data = $this->repo_storage_controller->execute('getItem', array(
            'item_id' => $item_id,
          )
        );
        return $data;
    }

    /**
     * Get Items
     *
     * Run a query to retrieve all items from the database.
     *
     * @param   int $subject_id  The subject ID
     * @return  array|bool     The query result
     */
    public function get_items($subject_id = false)
    {

        $items_data = $this->repo_storage_controller->execute('getItemsBySubjectId',
          array(
            'subject_id' => $subject_id,
          )
        );
        return $items_data;
    }

    /**
     * Get Items (for the tree browser)
     *
     * @Route("/admin/projects/get_items/{subject_id}", name="get_items_tree_browser", methods="GET")
     */
    public function get_items_tree_browser(Request $request, DatasetsController $datasets)
    {      
        $subject_id = !empty($request->attributes->get('subject_id')) ? $request->attributes->get('subject_id') : false;
        $items = $this->get_items($subject_id);

        foreach ($items as $key => $value) {

            // Truncate the item_description.
            $more_indicator = (strlen($value['item_description']) > 38) ? '...' : '';
            $value['item_description_truncated'] = substr($value['item_description'], 0, 38) . $more_indicator;

            // Check for child dataset records so the 'children' key can be set accordingly.
            $dataset_data = $datasets->get_datasets((int)$value['item_id']);
            $data[$key] = array(
                'id' => 'itemId-' . $value['item_id'],
                'children' => count($dataset_data) ? true : false,
                'text' => $value['item_description_truncated'],
                'a_attr' => array('href' => '/admin/projects/datasets/' . $value['project_id'] . '/' . $value['subject_id'] . '/' . $value['item_id']),
            );
        }

        $response = new JsonResponse($data);
        return $response;
    }

    /**
     * Get item_types
     * @return  array|bool  The query result
     */
    public function get_item_types()
    {
        $data = array();
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
            $data[$label] = $value['item_type_id'];
        }

        return $data;
    }

    /**
     * Delete Multiple Items
     *
     * @Route("/admin/projects/items/{project_id}/{subject_id}/delete", name="items_remove_records", methods={"GET"})
     * Run a query to delete multiple records.
     *
     * @param   int     $ids      The record ids
     * @param   object  $request  Request object
     * @return  void
     */
    public function delete_multiple_items(Request $request)
    {
        $ids = $request->query->get('ids');
        $project_id = !empty($request->attributes->get('project_id')) ? $request->attributes->get('project_id') : false;
        $subject_id = !empty($request->attributes->get('subject_id')) ? $request->attributes->get('subject_id') : false;

        if(!empty($ids) && $project_id && $subject_id) {

          $ids_array = explode(',', $ids);

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

        return $this->redirectToRoute('items_browse', array('project_id' => $project_id, 'subject_id' => $subject_id));
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
    public function updateStatus($itemguid = FALSE, $statusid = 0) {

        $updated = FALSE;
        if(!empty($itemguid)) {
            $ret = $this->repo_storage_controller->execute('markCaptureDatasetInactive', array(
              'item_guid' => $itemguid,
              'status_type_id' => $statusid,
            ));
            $updated = TRUE;
        }

        return $updated;
    }

}
