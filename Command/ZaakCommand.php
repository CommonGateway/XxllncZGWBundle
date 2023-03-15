<?php

namespace CommonGateway\XxllncZGWBundle\Command;

use CommonGateway\XxllncZGWBundle\Service\ZaakService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * This class handles the command for the synchronization of a xxllnc case to a zgw zrc zaak.
 *
 * This Command executes the zaakService->ZaakHandler.
 *
 * @author Barry Brands <barry@conduction.nl>
 *
 * @category Command
 */
class ZaakCommand extends Command
{

    /**
     * @var static $defaultName The actual command
     */
    protected static $defaultName = 'xxllnc:zaak:synchronize';

    /**
     * @var ZaakService
     */
    private ZaakService $zaakService;

    /**
     * __construct
     */
    public function __construct(ZaakService $zaakService)
    {
        $this->zaakService = $zaakService;
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
            ->setDescription('This command triggers Xxllnc ZaakService')
            ->setHelp('This command triggers Xxllnc ZaakService');

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
        $style = new SymfonyStyle($input, $output);
        $this->zaakService->setStyle($style);

        if ($this->zaakService->zaakHandler() === null) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    } // end execute()
}
