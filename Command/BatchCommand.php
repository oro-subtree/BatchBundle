<?php

namespace Oro\Bundle\BatchBundle\Command;

use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Validator\Validator;
use Doctrine\ORM\EntityManager;
use Oro\Bundle\BatchBundle\Entity\JobExecution;
use Symfony\Component\Validator\ConstraintViolationList;
use Oro\Bundle\BatchBundle\Job\ExitStatus;
use Oro\Bundle\BatchBundle\Job\BatchStatus;

/**
 * Batch command
 *
 */
class BatchCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('oro:batch:job')
            ->setDescription('Launch a registered job instance')
            ->addArgument('code', InputArgument::REQUIRED, 'Job instance code')
            ->addArgument('execution', InputArgument::OPTIONAL, 'Job execution id');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $noDebug = $input->getOption('no-debug');
        if (!$noDebug) {
            $logger = $this->getContainer()->get('logger');
            // Fixme: Use ConsoleHandler available on next Symfony version (2.4 ?)
            $logger->pushHandler(new StreamHandler('php://stdout'));
        }

        $code = $input->getArgument('code');
        $jobInstance = $this->getEntityManager()->getRepository('OroBatchBundle:JobInstance')->findOneByCode($code);
        if (!$jobInstance) {
            throw new \InvalidArgumentException(sprintf('Could not find job instance "%s".', $code));
        }

        $job = $this->getConnectorRegistry()->getJob($jobInstance);
        $jobInstance->setJob($job);

        $errors = $this->getValidator()->validate($jobInstance, array('Default', 'Execution'));
        if (count($errors) > 0) {
            throw new \RuntimeException(sprintf('Job "%s" is invalid: %s', $code, $this->getErrorMessages($errors)));
        }

        $executionId = $input->getArgument('execution');
        if ($executionId) {
            $jobExecution = $this->getEntityManager()->getRepository('OroBatchBundle:JobExecution')->find($executionId);
            if (!$jobExecution) {
                throw new \InvalidArgumentException(sprintf('Could not find job execution "%s".', $executionId));
            }
            if (!$jobExecution->getStatus()->isStarting()) {
                throw new \RuntimeException(
                    sprintf('Job execution "%s" has invalid status: %s', $executionId, $jobExecution->getStatus())
                );
            }
        } else {
            $jobExecution = new JobExecution();
        }
        $jobExecution->setJobInstance($jobInstance);

        $job->execute($jobExecution);

        $this->getEntityManager()->persist($jobInstance);
        $this->getEntityManager()->flush($jobInstance);

        $this->getEntityManager()->persist($jobExecution);
        $this->getEntityManager()->flush($jobExecution);

        if (ExitStatus::COMPLETED === $jobExecution->getExitStatus()->getExitCode()) {
            $output->writeln(
                sprintf(
                    '<info>%s has been successfully executed.</info>',
                    ucfirst($jobInstance->getType())
                )
            );
        } else {
            $output->writeln(
                sprintf(
                    '<error>An error occured during the %s execution.</error>',
                    $jobInstance->getType()
                )
            );
        }
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        return $this->getContainer()->get('doctrine')->getManager();
    }

    /**
     * @return Validator
     */
    protected function getValidator()
    {
        return $this->getContainer()->get('validator');
    }

    /**
     * @return \Oro\Bundle\BatchBundle\Connector\ConnectorRegistry
     */
    protected function getConnectorRegistry()
    {
        return $this->getContainer()->get('oro_batch.connectors');
    }

    private function getErrorMessages(ConstraintViolationList $errors)
    {
        $errorsStr = '';

        foreach ($errors as $error) {
            $errorsStr .= sprintf("\n  - %s", $error);
        }

        return $errorsStr;
    }
}
