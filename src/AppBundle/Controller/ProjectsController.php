<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\DBAL\Driver\Connection;

use PDO;

use AppBundle\Form\Project;
use AppBundle\Entity\Projects;

use AppBundle\Controller\RepoStorageHybridController;
use Symfony\Component\DependencyInjection\Container;

// Custom utility bundle
use AppBundle\Utils\AppUtilities;

class ProjectsController extends Controller
{
    /**
     * @var object $u
     */
    public $u;

    private $repo_controller;
    private $repo_storage_controller;

    /**
    * Constructor
    * @param object  $u  Utility functions object
    */
    public function __construct(AppUtilities $u)
    {
        // Usage: $this->u->dumper($variable);
        $this->u = $u;
        $this->repo_storage_controller = new RepoStorageHybridController();
        $this->repo_controller = new RepoStorageHybridController();

    }

    /**
     * @Route("/admin/workspace/", name="projects_browse", methods="GET")
     */
    public function browse_projects(Connection $conn, Request $request, IsniController $isni)
    {
        // Database tables are only created if not present.
      $this->repo_storage_controller->setContainer($this->container);
      $ret = $this->repo_storage_controller->build('createTable', array('table_name' => 'projects'));
      $ret = $this->repo_storage_controller->build('createTable', array('table_name' => 'isni_data'));

        return $this->render('projects/browse_projects.html.twig', array(
            'page_title' => 'Browse Projects',
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn)
        ));
    }

    /**
     * @Route("/admin/projects/datatables_browse_projects", name="projects_browse_datatables", methods="POST")
     *
     * Browse Projects
     *
     * Run a query to retreive all projects in the database.
     *
     * @param   object  Connection  Database connection object
     * @param   object  Request     Request object
     * @return  array|bool          The query result
     */
    public function datatables_browse_projects(Connection $conn, Request $request, SubjectsController $subjects)
    {

        $sort = '';
        $search_sql = '';
        $pdo_params = array();
        $data = array();

        $req = $request->request->all();
        $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
        $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
        $sort_order = $req['order'][0]['dir'];
        $start_record = !empty($req['start']) ? $req['start'] : 0;
        $stop_record = !empty($req['length']) ? $req['length'] : 20;
        $limit_sql = " LIMIT {$start_record}, {$stop_record} ";

        if (!empty($sort_field) && !empty($sort_order)) {
            $sort = " ORDER BY {$sort_field} {$sort_order}";
        } else {
            $sort = " ORDER BY projects.last_modified DESC ";
        }

        if ($search) {
            $pdo_params[] = '%' . $search . '%';
            $pdo_params[] = '%' . $search . '%';
            $pdo_params[] = '%' . $search . '%';
            $pdo_params[] = '%' . $search . '%';
            $search_sql = "
            AND (
                projects.project_name LIKE ?
                OR projects.stakeholder_label LIKE ?
                OR projects.date_created LIKE ?
                OR projects.last_modified LIKE ?
            ) ";
        }

        $statement = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS
                projects.project_repository_id as manage
                ,projects.project_repository_id
                ,projects.project_name
                ,projects.stakeholder_guid
                ,projects.date_created
                ,projects.last_modified
                ,projects.active
                ,projects.project_repository_id AS DT_RowId
                ,isni_data.isni_label AS stakeholder_label
            FROM projects
            LEFT JOIN isni_data ON isni_data.isni_id = projects.stakeholder_guid
            LEFT JOIN subjects ON subjects.project_repository_id = projects.project_repository_id
            WHERE projects.active = 1
            {$search_sql}
            GROUP BY projects.project_name, projects.stakeholder_guid, projects.date_created, projects.last_modified, projects.active, projects.project_repository_id
            {$sort}
            {$limit_sql}");
        $statement->execute($pdo_params);
        $data['aaData'] = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Get the subjects count
        if(!empty($data['aaData'])) {
            foreach ($data['aaData'] as $key => $value) {
                $project_subjects = $subjects->get_subjects($conn, $value['project_repository_id']);
                $data['aaData'][$key]['subjects_count'] = count($project_subjects);
            }
        }

        $statement = $conn->prepare("SELECT FOUND_ROWS()");
        $statement->execute();
        $count = $statement->fetch();
        $data["iTotalRecords"] = $count["FOUND_ROWS()"];
        $data["iTotalDisplayRecords"] = $count["FOUND_ROWS()"];
        
        return $this->json($data);
    }

    /**
     * Matches /admin/projects/manage/*
     *
     * @Route("/admin/projects/manage/{project_repository_id}", name="projects_manage", methods={"GET","POST"}, defaults={"project_repository_id" = null})
     *
     * @param   int     $project_repository_id  The project ID
     * @param   object  Connection    Database connection object
     * @param   object  Request       Request object
     * @return  array                 Redirect or render
     */
    function show_projects_form( $project_repository_id, Connection $conn, Request $request, IsniController $isni, UnitStakeholderController $unit )
    {

        $project = new Projects();
        $post = $request->request->all();
        $project_repository_id = !empty($request->attributes->get('project_repository_id')) ? $request->attributes->get('project_repository_id') : false;

        // Retrieve data from the database.
        $repo_controller = new RepoStorageHybridController();
        $repo_controller->setContainer($this->container);
        $project = (!empty($project_repository_id) && empty($post)) ? $repo_controller->execute('getProject', array('project_repository_id' => $project_repository_id)) : $project;
        
        // Get data from lookup tables.
        $project->stakeholder_guid_options = $this->get_units_stakeholders($conn);

        // Create the form
        $form = $this->createForm(Project::class, $project);
        // Handle the request
        $form->handleRequest($request);
        
        // If form is submitted and passes validation, insert/update the database record.
        if ($form->isSubmitted() && $form->isValid()) {

            $project = $form->getData();
            $project_repository_id = $this->insert_update_project($project, $project_repository_id, $conn, $isni, $unit);

            $this->addFlash('message', 'Project successfully updated.');
            return $this->redirect('/admin/projects/subjects/' . $project_repository_id);

        }

        return $this->render('projects/project_form.html.twig', array(
            'page_title' => !empty($project_repository_id) ? 'Project: ' . $project->project_name : 'Create Project',
            'project_data' => $project,
            'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
            'form' => $form->createView(),
        ));

    }

    /**
     * Get Projects
     *
     * Run a query to retrieve all projects from the database.
     *
     * @param   object  $conn  Database connection object
     * @return  array|bool     The query result
     */
    public function get_projects($conn)
    {
      $this->repo_storage_controller->setContainer($this->container);
      $data = $this->repo_controller->execute('getRecords', array(
        'base_table' => 'projects',
        'fields' => array(),
        'sort_fields' => array(
          'field_name' => 'stakeholder_guid'
        ),
        )
      );

/*      $statement = $conn->prepare("
            SELECT * FROM projects
            ORDER BY projects.stakeholder_guid ASC
        ");
*/
      return $data;
    }

    /**
     * Get Projects By Stakeholder GUID
     *
     * Run a query to retrieve all projects by a stakeholder GUID.
     *
     * @param   object  $conn              Database connection object
     * @param   string  $stakeholder_guid  Stakeholder GUID
     * @return  array|bool                 The query result
     */
    public function get_projects_by_stakeholder_guid($conn, $stakeholder_guid)
    {
        $this->repo_storage_controller->setContainer($this->container);
        $data = $this->repo_controller->execute('getRecords', array(
            'base_table' => 'projects',
            'fields' => array(),
            'sort_fields' => array(
              'field_name' => 'project_name'
            ),
            'search_params' => array(
              0 => array('field_names' => array('projects.active'), 'search_values' => array(1), 'comparison' => '='),
              1 => array('field_names' => array('projects.stakeholder_guid'), 'search_values' => $stakeholder_guid, 'comparison' => '=')
            ),
            'search_type' => 'AND'
          )
        );

        /*
        $statement = $conn->prepare("
            SELECT * FROM projects
            WHERE projects.stakeholder_guid = :stakeholder_guid
            AND projects.active = 1
            ORDER BY projects.project_name ASC
        ");
        $statement->bindValue(":stakeholder_guid", $stakeholder_guid, PDO::PARAM_STR);
        */
        return $data;
    }

    /**
     * Get Stakeholder GUIDs
     *
     * Run a query to retrieve all Stakeholder GUIDs from the database.
     *
     * @return  array|bool  The query result
     */
    public function get_stakeholder_guids($conn)
    {
        // $statement_fgb = $conn->prepare("
        //     SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));
        // ");
        // $statement_fgb->execute();

        $statement = $conn->prepare("
            SELECT projects.project_repository_id
                ,projects.stakeholder_guid
                ,isni_data.isni_label AS stakeholder_label
            FROM projects
            LEFT JOIN isni_data ON isni_data.isni_id = projects.stakeholder_guid
            WHERE projects.active = 1
            GROUP BY isni_data.isni_label
            ORDER BY isni_data.isni_label ASC
        ");
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Stakeholder GUIDs (route for the tree browser)
     *
     * @Route("/admin/projects/get_stakeholder_guids", name="get_stakeholder_guids_tree_browser", methods="GET")
     */
    public function get_stakeholder_guids_tree_browser(Connection $conn)
    {
      $projects = $this->get_stakeholder_guids($conn);

      foreach ($projects as $key => $value) {
          $data[$key]['id'] = 'stakeholderGuid-' . $value['stakeholder_guid'];
          $data[$key]['text'] = $value['stakeholder_label'];
          $data[$key]['children'] = true;
      }

      $response = new JsonResponse($data);
      return $response;
    }

    /**
     * Get a Stakeholder's Projects' (route for the tree browser)
     *
     * @Route("/admin/projects/get_stakeholder_projects/{stakeholder_guid}", name="get_stakeholder_projects_tree_browser", methods="GET")
     */
    public function get_stakeholder_projects_tree_browser(Connection $conn, Request $request, SubjectsController $subjects)
    {
        $data = array();
        $stakeholder_guid = !empty($request->attributes->get('stakeholder_guid')) ? $request->attributes->get('stakeholder_guid') : false;
        $projects = $this->get_projects_by_stakeholder_guid($conn, $stakeholder_guid);

        foreach ($projects as $key => $value) {

            // Check for child dataset records so the 'children' key can be set accordingly.
            $subject_data = $subjects->get_subjects($conn, (int)$value['project_repository_id']);

            $data[$key] = array(
                'id' => 'projectId-' . $value['project_repository_id'],
                'text' => $value['project_name'],
                'children' => count($subject_data) ? true : false,
                'a_attr' => array('href' => '/admin/projects/subjects/' . $value['project_repository_id']),
            );
            
        }

        $response = new JsonResponse($data);
        return $response;
    }

    /**
     * Insert/Update Project
     *
     * Run queries to insert and update projects in the database.
     *
     * @param   array   $data        The data array
     * @param   int     $project_id  The project ID
     * @param   object  $conn        Database connection object
     * @return  int     The project ID
     */
    public function insert_update_project($data, $project_repository_id = FALSE, $conn, $isni, $unit)
    {

        $unit_record = $unit->get_one($data->stakeholder_guid_picker, $conn);

        if($unit_record && !empty($unit_record['isni_id'])) {
          $data->stakeholder_guid = $unit_record['isni_id'];
        } else {
          $data->stakeholder_guid = $data->stakeholder_guid_picker;
        }

        // Query the isni_data table to see if there's an entry.
        $isni_data = $isni->get_isni_data_from_database($data->stakeholder_guid, $conn);

        // If there is no entry, then perform an insert.
        if(!$isni_data) {
          $isni_inserted = $isni->insert_isni_data($data->stakeholder_guid, $data->stakeholder_label, $this->getUser()->getId(), $conn);
        }

        // Update
        if($project_repository_id) {

            $statement = $conn->prepare("
                UPDATE projects
                SET project_name = :project_name
                ,stakeholder_guid = :stakeholder_guid
                ,project_description = :project_description
                ,last_modified_user_account_id = :last_modified_user_account_id
                WHERE project_repository_id = :project_repository_id
                ");
            $statement->bindValue(":project_name", $data->project_name, PDO::PARAM_STR);
            $statement->bindValue(":stakeholder_guid", $data->stakeholder_guid, PDO::PARAM_STR);
            $statement->bindValue(":project_description", $data->project_description, PDO::PARAM_STR);
            $statement->bindValue(":last_modified_user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->bindValue(":project_repository_id", $project_repository_id, PDO::PARAM_INT);
            $statement->execute();

            return $project_repository_id;
        }

        // Insert
        if(!$project_repository_id) {

            $statement = $conn->prepare("INSERT INTO projects
              (project_name, stakeholder_guid, project_description, date_created, created_by_user_account_id, last_modified_user_account_id )
              VALUES (:project_name, :stakeholder_guid, :project_description, NOW(), :user_account_id, :user_account_id )");
            $statement->bindValue(":project_name", $data->project_name, PDO::PARAM_STR);
            $statement->bindValue(":stakeholder_guid", $data->stakeholder_guid, PDO::PARAM_STR);
            $statement->bindValue(":project_description", $data->project_description, PDO::PARAM_STR);
            $statement->bindValue(":user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->execute();
            $last_inserted_id = $conn->lastInsertId();

            if(!$last_inserted_id) {
              die('INSERT INTO `projects` failed.');
            }

            return $last_inserted_id;
        }

    }

    /**
     * Get unit_stakeholder
     * @return  array|bool  The query result
     */
    public function get_units_stakeholders($conn)
    {
      $data = array();

      $statement = $conn->prepare("SELECT * FROM unit_stakeholder
        WHERE unit_stakeholder.active = 1
        ORDER BY unit_stakeholder_label ASC");
      $statement->execute();

      foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $key => $value) {
        $data[$value['unit_stakeholder_label'] . ' - ' . $value['unit_stakeholder_full_name']] = $value['unit_stakeholder_id'];
      }

      return $data;
    }

    /**
     * Delete Multiple Projects
     *
     * Matches /admin/workspace/delete
     *
     * @Route("/admin/workspace/delete", name="projects_remove_records", methods={"GET"})
     * Run a query to delete multiple records.
     *
     * @param   int     $ids      The record ids
     * @param   object  $conn     Database connection object
     * @param   object  $request  Request object
     * @return  void
     */
    public function delete_multiple_projects(Connection $conn, Request $request)
    {
        $ids = $request->query->get('ids');

        if(!empty($ids)) {

          $ids_array = explode(',', $ids);

          foreach ($ids_array as $key => $id) {

            $statement = $conn->prepare("
                UPDATE projects
                LEFT JOIN subjects ON subjects.project_repository_id = projects.project_repository_id
                LEFT JOIN items ON items.subject_repository_id = subjects.subject_repository_id
                LEFT JOIN capture_datasets ON capture_datasets.parent_item_repository_id = items.item_repository_id
                LEFT JOIN capture_data_elements ON capture_data_elements.capture_dataset_repository_id = capture_datasets.capture_dataset_repository_id
                SET projects.active = 0,
                    projects.last_modified_user_account_id = :last_modified_user_account_id,
                    subjects.active = 0,
                    subjects.last_modified_user_account_id = :last_modified_user_account_id,
                    items.active = 0,
                    items.last_modified_user_account_id = :last_modified_user_account_id,
                    capture_datasets.active = 0,
                    capture_datasets.last_modified_user_account_id = :last_modified_user_account_id,
                    capture_data_elements.active = 0,
                    capture_data_elements.last_modified_user_account_id = :last_modified_user_account_id
                WHERE projects.project_repository_id = :id
            ");
            $statement->bindValue(":id", $id, PDO::PARAM_INT);
            $statement->bindValue(":last_modified_user_account_id", $this->getUser()->getId(), PDO::PARAM_INT);
            $statement->execute();

          }

          $this->addFlash('message', 'Records successfully removed.');

        } else {
          $this->addFlash('message', 'Missing data. No records removed.');
        }

        return $this->redirectToRoute('projects_browse');
    }

    /**
     * Delete Project
     *
     * Run a query to delete a project from the database.
     *
     * @param   int     $project_id  The project ID
     * @param   object  $conn        Database connection object
     * @return  void
     */
    public function delete_project($project_repository_id, $conn)
    {
        $statement = $conn->prepare("
            DELETE FROM projects
            WHERE project_repository_id = :project_repository_id");
        $statement->bindValue(":project_repository_id", $project_repository_id, PDO::PARAM_INT);
        $statement->execute();

        // First, delete all subjects.
        $statement = $conn->prepare("
            DELETE FROM subjects
            WHERE project_repository_id = :project_repository_id");
        $statement->bindValue(":project_repository_id", $project_repository_id, PDO::PARAM_INT);
        $statement->execute();
    }

}
