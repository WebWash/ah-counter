<?php

declare(strict_types=1);

namespace App\Demo;

use App\Entity\Speech;
use App\Entity\SpeechStatus;
use App\Repository\SpeechRepository;
use App\Speech\RecordingStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Demo Speeches as files (SPEC §11): each is demo/{name}.json, holding every Speech field and its
 * fixed ID, plus its Recording as demo/{name}.{ext}. Loading them needs no API keys.
 */
final readonly class DemoData
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function __construct(
        #[Autowire('%app.demo_dir%')]
        private string $demoDir,
        private RecordingStorage $recordings,
        private SpeechRepository $speeches,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Writes a done Speech and its Recording to the demo folder.
     *
     * @return list<string> the files written
     *
     * @throws InvalidDemoName
     * @throws DemoDataException
     */
    public function export(Speech $speech, string $name, bool $overwrite = false): array
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidDemoName($name);
        }
        if (SpeechStatus::Done !== $speech->getStatus()) {
            throw new DemoDataException(\sprintf('Speech %s is still %s; only a done Speech can be a demo.', $speech->getId(), $speech->getStatus()->value));
        }

        if (null === $speech->coaching()) {
            throw new DemoDataException(\sprintf('Speech %s has no Coaching (it was processed before AI Coaching existed). Record a new Speech for the demo.', $speech->getId()));
        }

        $source = $this->recordings->path($speech);
        if (!is_file($source)) {
            throw new DemoDataException(\sprintf('The Recording of Speech %s is missing (%s).', $speech->getId(), $source));
        }

        $recording = $name.'.'.pathinfo($speech->getRecordingFilename(), \PATHINFO_EXTENSION);
        $json = $this->demoDir.'/'.$name.'.json';
        $audio = $this->demoDir.'/'.$recording;
        if (!$overwrite && (is_file($json) || is_file($audio))) {
            throw new DemoDataException(\sprintf('demo/%s.json already exists. Pass --force to replace it.', $name));
        }

        $this->filesystem->copy($source, $audio, true);
        $this->filesystem->dumpFile($json, json_encode(
            ['recording' => $recording, ...$speech->toExport()],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        )."\n");

        return [$json, $audio];
    }

    /**
     * Creates every demo Speech not in the database yet, with its Recording. No API calls.
     *
     * @return list<DemoLoad> in file name order
     *
     * @throws DemoDataException when there are no demo files or one is malformed
     */
    public function load(): array
    {
        $files = glob($this->demoDir.'/*.json') ?: [];
        sort($files);
        if ([] === $files) {
            throw new DemoDataException(\sprintf('There are no demo Speeches in %s. Record a Speech, then run bin/console app:export-demo <id> <name>.', $this->demoDir));
        }

        $loads = [];
        foreach ($files as $file) {
            $data = $this->read($file);
            try {
                $speech = Speech::fromExport($data);
            } catch (\InvalidArgumentException $e) {
                throw new DemoDataException(\sprintf('%s: %s', basename($file), $e->getMessage()), 0, $e);
            }

            if (null !== $this->speeches->find($speech->getId())) {
                $loads[] = new DemoLoad($speech, basename($file), false);
                continue;
            }

            $audio = $this->demoDir.'/'.($data['recording'] ?? '');
            if (!\is_string($data['recording'] ?? null) || !is_file($audio)) {
                throw new DemoDataException(\sprintf('%s: its Recording %s is missing.', basename($file), $audio));
            }

            $this->filesystem->copy($audio, $this->recordings->path($speech), true);
            $this->entityManager->persist($speech);
            $this->entityManager->flush();
            $loads[] = new DemoLoad($speech, basename($file), true);
        }

        return $loads;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DemoDataException(\sprintf('%s is not valid JSON: %s', basename($file), $e->getMessage()), 0, $e);
        }

        return \is_array($data) ? $data : throw new DemoDataException(\sprintf('%s does not hold a Speech.', basename($file)));
    }
}
