<?php
namespace Exercise\GoogleTranslateBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GoogleTranslateCommand extends ContainerAwareCommand
{
    protected $progress;

    protected function configure()
    {
        $this
            ->setName('gtranslate:translate')
            ->setDefinition(array(
                new InputArgument('localeFrom', InputArgument::REQUIRED, 'The locale'),
                new InputArgument('localeTo', InputArgument::REQUIRED, 'The locale'),
                new InputArgument('bundle', InputArgument::REQUIRED, 'The bundle where to load the messages'),
            ))
            ->addOption('override', null, InputOption::VALUE_NONE, 'If set and file with locateTo exist - it will be replaced with new translated file')
            ->setDescription('translate message files in your project with Google Translate');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $foundBundle = $this->getApplication()->getKernel()->getBundle($input->getArgument('bundle'));
        $basePath = $foundBundle->getPath() . '/Resources/translations';

        if (!is_dir($basePath)) {
            throw new \Exception('Bundle has no translation message!');
        }

        if ($handle = opendir($basePath)) {

            while (false !== ($messageFromFileName = readdir($handle))) {
                //ToDo make handler for other formats (xml, php)
                if (false !== strpos($messageFromFileName, $input->getArgument('localeFrom').'.yml')) {

                    $messageToFileName = str_replace($input->getArgument('localeFrom'), $input->getArgument('localeTo'), $messageFromFileName);

                    $messagesFrom = Yaml::parse(sprintf("%s/$messageFromFileName", $basePath));
                    $messagesTo   = Yaml::parse(sprintf("%s/$messageToFileName", $basePath));

                    //If override - translate all message again, even if it had been translated
                    if ($input->getOption('override')) {
                        $arrayDiff = $messagesFrom;
                    } else {
                        $arrayDiff = $this->arrayDiffKeyRecursive($messagesFrom, $messagesTo);
                    }

                    //If nothing to translate - exit
                    if (!$count = count($arrayDiff, COUNT_RECURSIVE)) {
                        $output->writeln('Nothing to translate!');
                        continue;
                    }

                    $this->progress = $this->getHelperSet()->get('progress');
                    $this->progress->start($output, $count);

                    $translatedArray = $this->translateArray($arrayDiff, $input->getArgument('localeFrom'), $input->getArgument('localeTo'));

                    if ($input->getOption('override') || !is_array($messagesTo)) {
                        $messagesTo = $translatedArray;
                    } else {
                        $messagesTo = array_merge_recursive($translatedArray, $messagesTo);
                    }

                    $this->progress->finish();

                    $output->writeln(sprintf('Creating "<info>%s</info>" file', $messageToFileName));

                    $file = $basePath . '/' . $messageToFileName;
                    $messagesTo = $this->ksortRecursive($messagesTo);
                    file_put_contents($file, Yaml::dump($messagesTo, 100500));
                }
            }
        }

        $output->writeln('Translate is success!');
    }

    /**
     * Recursive translate array value message
     *
     * @param $array array
     * @param $langFrom string
     * @param $langTo string
     * @return array
     */
    public function translateArray(array $array, $langFrom, $langTo)
    {
        $translator = $this->getContainer()->get('exercise_google_translate.translator');

        foreach ($array as $key => $value) {

            if (is_array($value)) {
                $array[$key] = $this->translateArray($value, $langFrom, $langTo);
            } else {
                $array[$key] = $translator->translate($value, $langFrom, $langTo);
            }

            $this->progress->advance();
        }

        return $array;
    }

    /**
     * @param $array1 array messagesFrom
     * @param $array2 array messagesTo
     * @return array
     */
    protected function arrayDiffKeyRecursive($array1, $array2)
    {
        if (is_array($array2)) {
            $resultArray = array_diff_key($array1, $array2);
        } else {
            return $array1;
        }

        foreach ($array1 as $key => $value) {

            if (!isset($array2[$key])) {
                $resultArray[$key] = $array1[$key];
            } elseif (is_array($value)) {
                $resultArray[$key] = $this->arrayDiffKeyRecursive( $array1[$key], $array2[$key]);

                if (is_array($resultArray[$key]) && count($resultArray[$key]) == 0) {
                    unset($resultArray[$key]);
                }
            }
        }

        return $resultArray;
    }

    protected function ksortRecursive(array $array)
    {
        foreach ($array as $key => $nestedArray) {
            if (is_array($nestedArray) && !empty($nestedArray)) {
                $array[$key] = $this->ksortRecursive($nestedArray);
            }
        }

        ksort($array);

        return $array;
    }
}
