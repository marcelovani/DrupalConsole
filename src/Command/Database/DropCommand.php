<?php

/**
 * @file
 * Contains \Drupal\Console\Command\Database\DropCommand.
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
 * Class DropCommand
 *
 * @package Drupal\Console\Command\Database
 */
class DropCommand extends Command
{
    use ConnectTrait;

    /**
     * @var string
     */
    protected $appRoot;

    /**
     * DropCommand constructor.
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
            ->setName('database:drop')
            ->setDescription($this->trans('commands.database.drop.description'))
            ->addArgument(
                'database',
                InputArgument::OPTIONAL,
                $this->trans('commands.database.drop.arguments.database'),
                'default'
            )
            ->setHelp($this->trans('commands.database.drop.help'))
            ->setAliases(['dbd']);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $database = $input->getArgument('database');
        $yes = $input->getOption('yes');
        $learning = $input->getOption('learning');
        $noInteraction = $input->getOption('no-interaction');

        $databaseConnection = $this->resolveConnection($database);

        if (!$yes && !$noInteraction) {
            if (!$this->getIo()->confirm(
                sprintf(
                    $this->trans('commands.database.drop.question.drop-tables'),
                    $databaseConnection['database']
                ),
                true
            )
            ) {
                return 1;
            }
        }

        $commands[] = array(
            'command' => 'database:query',
            'arguments' => array(
                'query' => sprintf(
                    'DROP DATABASE IF EXISTS %s',
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
                $this->trans('commands.database.drop.messages.db-drop'),
                $databaseConnection['database']
            )
        );

        return 0;
    }
}
