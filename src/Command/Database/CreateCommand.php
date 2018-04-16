<?php

/**
 * @file
 * Contains \Drupal\Console\Command\Database\CreateCommand.
 */

namespace Drupal\Console\Command\Database;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Drupal\Console\Core\Command\Command;
use Drupal\Core\Database\Connection;
use Drupal\Console\Command\Shared\ConnectTrait;

/**
 * Class CreateCommand
 *
 * @package Drupal\Console\Command\Database
 */
class CreateCommand extends Command
{
    use ConnectTrait;

    /**
     * @var string
     */
    protected $appRoot;

    /**
     * CreateCommand constructor.
     *
     * @param Connection $database
     */
    public function __construct($appRoot)
    {
        $this->appRoot = $appRoot;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('database:create')
            ->setDescription($this->trans('commands.database.create.description'))
            ->addArgument(
                'database',
                InputArgument::OPTIONAL,
                $this->trans('commands.database.create.arguments.database'),
                'default'
            )
            ->setHelp($this->trans('commands.database.create.help'))
            ->setAliases(['dbcr']);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $database = $input->getArgument('database');
        $learning = $input->getOption('learning');

        $databaseConnection = $this->resolveConnection($database);

        $commands[] = array(
            'command' => 'database:query',
            'arguments' => array(
                'query' => sprintf(
                    'CREATE DATABASE IF NOT EXISTS %s',
                    $databaseConnection['database']
                )
            ),
            'options' => array(
                'learning' => $learning,
            ),
        );

        $this->runCommands($commands);

        if ($this->runCommands($commands) != 0) {
            return 1;
        }

        $this->getIo()->success(
            sprintf(
                $this->trans('commands.database.create.messages.created'),
                $databaseConnection['database']
            )
        );

        return 0;
    }
}
