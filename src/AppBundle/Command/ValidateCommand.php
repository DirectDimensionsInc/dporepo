<?php

namespace AppBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\HttpKernel\KernelInterface;

use AppBundle\Service\RepoImport;
use AppBundle\Service\RepoValidateData;
use AppBundle\Controller\RepoStorageHybridController;

class ValidateCommand extends Command
{
  private $repo_import;
  private $validate;
  private $project_directory;

  public function __construct(KernelInterface $kernel, RepoImport $repo_import, RepoValidateData $validate, string $uploads_directory, bool $external_file_storage_on, object $conn)
  {
    // Repo Import service
    $this->repo_import = $repo_import;
    // Repo Validate Data service
    $this->validate = $validate;
    // Storage controller
    $this->repo_storage_controller = new RepoStorageHybridController($conn);
    // Uploads directory
    $this->kernel = $kernel;
    $this->project_directory = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR;
    $this->uploads_directory = (DIRECTORY_SEPARATOR === '\\') ? $this->project_directory . str_replace('\\', '/', $uploads_directory) : $this->project_directory . $uploads_directory;
    $this->external_file_storage_on = $external_file_storage_on;
    // This is required due to parent constructor, which sets up name.
    parent::__construct();
  }

  protected function configure()
  {
    $this
      // The name of the command (the part after "bin/console").
      ->setName('app:validate-assets')
      // The short description shown while running "php bin/console list".
      ->setDescription('Validate uploaded assets.')
      // The full command description shown when running the command with
      // the "--help" option.
      ->setHelp('This command will 1) validate a BagIt "bag", which consists of a "payload" (the arbitrary content) and "tags", which are metadata files intended to document the storage and transfer of the "bag", and 2) validate the integrity of uploaded files.')
      // Add arguments...
      ->addArgument('uuid', InputArgument::OPTIONAL, 'Job UUID.')
      ->addArgument('parent_project_id', InputArgument::OPTIONAL, 'parent_project_id.')
      ->addArgument('parent_record_id', InputArgument::OPTIONAL, 'parent_record_id.')
      ->addArgument('parent_record_type', InputArgument::OPTIONAL, 'parent_record_type.')
      ->addArgument('localpath', InputArgument::OPTIONAL, 'The path to the directory to validate.');
  }

  /**
   * Example:
   * php bin/console app:validate-assets 3df_5b91d293515604.56745643 2 2 project
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $result = '';

    // Outputs multiple lines to the console (adding "\n" at the end of each line).
    $output->writeln([
      '',
      '<bg=green;options=bold>  =====================  </>',
      '<bg=green;options=bold>  == Validate Assets ==  </>',
      '<bg=green;options=bold>  =====================  </>',
      '',
      'Command: ' . 'php bin/console app:validate-assets ' . $input->getArgument('uuid') . ' ' . $input->getArgument('parent_project_id') . ' ' . $input->getArgument('parent_record_id') . ' ' . $input->getArgument('parent_record_type') . "\n",
    ]);

    // First, check to see if the external storage is accessible (Drastic).
    // If the external storage is not accessible, then the job status will be set to 'failed', 
    // which will prevent any further validations and file transfers from executing.
    $external_storage_check = $this->getApplication()->find('app:transfer-files');

    $arguments_external_storage_check = array(
        'command' => 'app:transfer-files',
        'uuid' => $input->getArgument('uuid'),
        'check_external_storage' => true
    );

    $input_external_storage_check = new ArrayInput($arguments_external_storage_check);
    $return_external_storage_check = $external_storage_check->run($input_external_storage_check, $output);

    // If a localpath is passed, use it as the path to the files to validate.
    if ( !empty($input->getArgument('localpath')) ) {
      $directory_to_validate = $input->getArgument('localpath');
    }

    // If a localpath is NOT passed, check the database for a job with the 'job_status' set to 'bagit validation starting'.
    if ( empty($input->getArgument('localpath')) ) {
      $directory_to_validate = $this->validate->needsValidationChecker('bagit validation starting', $this->uploads_directory);
    }

    if (!empty($directory_to_validate)) {

      sleep(5);

      // Run the BagIt validation.
      $command_bagit = $this->getApplication()->find('app:bagit-validate');
      $arguments_bagit = array(
          'command' => 'app:bagit-validate',
          'localpath' => $directory_to_validate
      );
      $input_bagit = new ArrayInput($arguments_bagit);
      $return_bagit = $command_bagit->run($input_bagit, $output);

      sleep(5);

      // Run the files validation.
      $command_files = $this->getApplication()->find('app:files-validate');
      $arguments_files = array(
          'command' => 'app:files-validate',
          'localpath' => $directory_to_validate
      );
      $input_files = new ArrayInput($arguments_files);
      $return_files = $command_files->run($input_files, $output);

      sleep(5);

      // Run the models validation.
      $command_models = $this->getApplication()->find('app:model-validate');
      $arguments_models = array(
          'command' => 'app:model-validate',
          'uuid' => $input->getArgument('uuid')
      );
      $input_models = new ArrayInput($arguments_models);
      $return_models = $command_models->run($input_models, $output);

      sleep(5);

      // Outputs multiple lines to the console (adding "\n" at the end of each line).
      $output->writeln([
        '',
        '<bg=blue;options=bold> Ingesting Metadata </>',
        '',
        'Parameters: ' . "\n",
        'uuid: ' . $input->getArgument('uuid'),
        'parent_project_id: ' . $input->getArgument('parent_project_id'),
        'parent_record_id: ' . $input->getArgument('parent_record_id'),
        'parent_record_type: ' . $input->getArgument('parent_record_type') . "\n"
      ]);

      // Run the metadata ingest.
      $params = array(
        'uuid' => $input->getArgument('uuid'),
        'parent_project_id' => $input->getArgument('parent_project_id'),
        'parent_record_id' => $input->getArgument('parent_record_id'),
        'parent_record_type' => $input->getArgument('parent_record_type'),
      );

      $import_results = $this->repoImport->import_csv($params);
      
      // echo '<pre>';
      // var_dump($import_results);
      // echo '</pre>';
      // die();

      if (isset($import_results['errors'])) {
        $output->writeln('<comment>Metadata ingest failed. Errors: ' . implode(', ', $import_results['errors']) . '</comment>');
      } else {
        $output->writeln('<comment>Metadata ingest complete.</comment>');

        // Transfer files.
        $command_file_transfer = $this->getApplication()->find('app:transfer-files');

        $arguments_file_transfer = array(
            'command' => 'app:transfer-files',
            'uuid' => $input->getArgument('uuid')
        );

        $input_file_transfer = new ArrayInput($arguments_file_transfer);
        $return_file_transfer = $command_file_transfer->run($input_file_transfer, $output);
      }

    }

    // If there's no $directory_to_validate, display a message.
    if(empty($directory_to_validate)) {
      $output->writeln('<comment>No jobs found to validate</comment>');
    }   
  }
}