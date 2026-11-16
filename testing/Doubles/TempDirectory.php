<?php

/*
 * A temporary directory for a test's files, which is deleted (along with the files in it) by remove().
 */

declare(strict_types = 1);
namespace Programster\Log\Testing\Doubles;


final readonly class TempDirectory
{
    private string $m_path;


    /**
     * Create a new, empty temporary directory.
     * @throws \RuntimeException - if it can't be created.
     */
    public function __construct()
    {
        $this->m_path = sys_get_temp_dir() . "/package-logging-test-" . bin2hex(random_bytes(8));

        if (!mkdir($this->m_path))
        {
            throw new \RuntimeException("Couldn't create the temporary directory {$this->m_path}.");
        }
    }


    /**
     * Delete the directory, and every file (and empty directory) in it.
     * @return void
     */
    public function remove() : void
    {
        $paths = glob("{$this->m_path}/*") ?: [];

        foreach ($paths as $path)
        {
            if (is_dir($path))
            {
                rmdir($path);
            }
            else
            {
                unlink($path);
            }
        }

        rmdir($this->m_path);
    }


    # Accessors
    public function getPath() : string { return $this->m_path; }
}
