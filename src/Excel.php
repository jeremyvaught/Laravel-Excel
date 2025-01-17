<?php

namespace Maatwebsite\Excel;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use Maatwebsite\Excel\Files\Filesystem;
use Maatwebsite\Excel\Files\TemporaryFile;
use Maatwebsite\Excel\Helpers\FileTypeDetector;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Excel implements Exporter, Importer
{
    use Macroable, RegistersCustomConcerns;

    const XLSX     = 'Xlsx';

    const CSV      = 'Csv';

    const TSV      = 'Csv';

    const ODS      = 'Ods';

    const XLS      = 'Xls';

    const SLK      = 'Slk';

    const XML      = 'Xml';

    const GNUMERIC = 'Gnumeric';

    const HTML     = 'Html';

    const MPDF     = 'Mpdf';

    const DOMPDF   = 'Dompdf';

    const TCPDF    = 'Tcpdf';

    /**
     * @var Writer
     */
    protected $writer;

    /**
     * @var QueuedWriter
     */
    protected $queuedWriter;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @param  Writer  $writer
     * @param  QueuedWriter  $queuedWriter
     * @param  Reader  $reader
     * @param  Filesystem  $filesystem
     */
    public function __construct(
        Writer $writer,
        QueuedWriter $queuedWriter,
        Reader $reader,
        Filesystem $filesystem
    ) {
        $this->writer       = $writer;
        $this->reader       = $reader;
        $this->filesystem   = $filesystem;
        $this->queuedWriter = $queuedWriter;
    }

    /**
     * {@inheritdoc}
     */
    public function download($export, string $fileName, ?string $writerType = null, array $headers = []): BinaryFileResponse
    {
        // Clear output buffer to prevent stuff being prepended to the Excel output.
        if (ob_get_length() > 0) {
            ob_end_clean();
            ob_start();
        }

        return response()->download(
            $this->export($export, $fileName, $writerType)->getLocalPath(),
            $fileName,
            $headers
        )->deleteFileAfterSend(true);
    }

    /**
     * {@inheritdoc}
     */
    public function store($export, string $filePath, ?string $disk = null, ?string $writerType = null, mixed $diskOptions = []): bool|PendingDispatch
    {
        if ($export instanceof ShouldQueue) {
            return $this->queue($export, $filePath, $disk, $writerType);
        }

        $temporaryFile = $this->export($export, $filePath, $writerType);

        $exported = $this->filesystem->disk($disk, $diskOptions)->copy(
            $temporaryFile,
            $filePath
        );

        $temporaryFile->delete();

        return $exported;
    }

    /**
     * {@inheritdoc}
     */
    public function queue($export, string $filePath, ?string $disk = null, ?string $writerType = null, mixed $diskOptions = []): PendingDispatch
    {
        $writerType = FileTypeDetector::detectStrict($filePath, $writerType);

        return $this->queuedWriter->store(
            $export,
            $filePath,
            $disk,
            $writerType
        );
    }

    /**
     * {@inheritdoc}
     */
    public function raw($export, string $writerType): string
    {
        $temporaryFile = $this->writer->export($export, $writerType);

        $contents = $temporaryFile->contents();
        $temporaryFile->delete();

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function import($import, $file, ?string $disk = null, ?string $readerType = null)
    {
        $response = $this->reader->read($import, $file, $readerType, $disk);

        if ($response instanceof PendingDispatch) {
            return $response;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray($import, $file, ?string $disk = null, ?string $readerType = null): array
    {
        return $this->reader->toArray($import, $file, $readerType, $disk);
    }

    /**
     * {@inheritdoc}
     */
    public function toCollection($import, $file, ?string $disk = null, ?string $readerType = null): Collection
    {
        return $this->reader->toCollection($import, $file, $readerType, $disk);
    }

    /**
     * {@inheritdoc}
     */
    public function queueImport(ShouldQueue $import, $file, ?string $disk = null, ?string $readerType = null): PendingDispatch
    {
        return $this->reader->read($import, $file, $readerType, $disk);
    }

    /**
     * @param  object  $export
     * @param  string|null  $fileName
     * @param  string  $writerType
     * @return TemporaryFile
     *
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    protected function export($export, string $fileName, ?string $writerType = null): TemporaryFile
    {
        $writerType = FileTypeDetector::detectStrict($fileName, $writerType);

        return $this->writer->export($export, $writerType);
    }
}
