<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\XxllncToZGWZaakService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * This class handles the command for the synchronization of a xxllnc case to a zgw zrc zaak.
 *
 * This Command executes the xxllncToZGWZaakService->xxllncToZGWZaakHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Command
 */
class XxllncToZGWZaakCommand extends Command
{

    /**
     * @var static $defaultName The actual command
     */
    protected static $defaultName = 'xxllnc:xxllncToZGWZaak:execute';

    /**
     * @var XxllncToZGWZaakService
     */
    private XxllncToZGWZaakService $xxllncToZGWZaakService;

    /**
     * __construct
     */
    public function __construct(XxllncToZGWZaakService $xxllncToZGWZaakService)
    {
        $this->xxllncToZGWZaakService = $xxllncToZGWZaakService;
        parent::__construct();
    }//end __construct()

    /**
     * Configures this command
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers Xxllnc XxllncToZGWZaakService')
            ->setHelp('This command triggers Xxllnc XxllncToZGWZaakService');
    }//end configure()

   /**
    * Executes this command
    * 
    * @param InputInterface  Handles input from cli
    * @param OutputInterface Handles output from cli
    * 
    * @return int 0 for failure, 1 for success
    */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->xxllncToZGWZaakService->setStyle($io);

        if (!$this->xxllncToZGWZaakService->xxllncToZGWZaakHandler()) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }// end execute()
}
