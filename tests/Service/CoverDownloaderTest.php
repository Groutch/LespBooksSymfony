<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Book;
use App\Entity\BookImage;
use App\Service\Catalog\CoverDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CoverDownloaderTest extends KernelTestCase
{
    /**
     * Regression : la couverture etait enregistree sans fichier, VichUploader
     * ignorant en silence un File qui n'est ni UploadedFile ni ReplacingFile.
     */
    public function testDownloadedCoverIsStoredOnDisk(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $downloader = new CoverDownloader(
            new MockHttpClient(new MockResponse($this->pngBytes(), ['http_code' => 200])),
            new NullLogger(),
        );

        $file = $downloader->download('https://books.google.com/books/content?id=test', '9782070368228');
        self::assertNotNull($file, 'Une réponse image valide doit produire un fichier.');

        $book = (new Book())->setTitle('Livre avec couverture téléchargée');
        $image = (new BookImage())->setPrimary(true)->setPosition(0);
        $image->setImageFile($file);
        $book->addImage($image);

        $entityManager->persist($book);
        $entityManager->flush();

        $imageName = $image->getImageName();
        self::assertNotNull($imageName, 'VichUploader doit renseigner imageName au flush.');

        $stored = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/books/'.$imageName;
        self::assertFileExists($stored);

        unlink($stored);
        $entityManager->remove($book);
        $entityManager->flush();
    }

    public function testCoverFromAnUnknownHostIsRefused(): void
    {
        self::bootKernel();

        $downloader = new CoverDownloader(
            new MockHttpClient(new MockResponse($this->pngBytes(), ['http_code' => 200])),
            new NullLogger(),
        );

        self::assertNull($downloader->download('https://exemple-inconnu.test/couverture.png', 'ref'));
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(20, 30);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
