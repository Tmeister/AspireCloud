<?php

namespace App\Service\WpOrg;

use App\Repository\PluginRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class Plugin_1_2_API_Service {
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PluginRepository $pluginRepository
    ) {
        //
    }

    public function getPluginInformation( string $slug ): array {
        $plugin = $this->pluginRepository->findOneBy( [ 'slug' => $slug ] );

        if ( ! $plugin ) {
            throw new NotFoundHttpException( 'Plugin not found' );
        }

        return [
            'name'                     => $plugin->getName(),
            'slug'                     => $plugin->getSlug(),
            'version'                  => $plugin->getVersion(),
            'author'                   => $plugin->getAuthor(),
            'author_profile'           => $plugin->getAuthorProfile(),
            'requires'                 => $plugin->getRequires(),
            'tested'                   => $plugin->getTested(),
            'requires_php'             => $plugin->getRequiresPhp(),
            'rating'                   => $plugin->getRating(),
            'ratings'                  => $plugin->getRatings(),
            'num_ratings'              => $plugin->getNumRatings(),
            'support_threads'          => $plugin->getSupportThreads(),
            'support_threads_resolved' => $plugin->getSupportThreadsResolved(),
            'active_installs'          => $plugin->getActiveInstalls(),
            'downloaded'               => $plugin->getDownloaded(),
            'last_updated'             => $plugin->getLastUpdated()->format( 'Y-m-d H:i:s' ),
            'added'                    => $plugin->getAdded()->format( 'Y-m-d' ),
            'homepage'                 => $plugin->getHomepage(),
            'sections'                 => $plugin->getSections(),
            'download_link'            => $plugin->getDownloadLink(),
            'tags'                     => $plugin->getTags(),
            'versions'                 => $plugin->getVersions(),
            'donate_link'              => $plugin->getDonateLink(),
            'contributors'             => $plugin->getContributors(),
            'screenshots'              => $plugin->getScreenshots(),
        ];
    }

    public function queryPlugins(): array {
        $plugins = $this->pluginRepository->findAll();
        $result  = [];

        foreach ( $plugins as $plugin ) {
            $result[] = [
                'name'            => $plugin->getName(),
                'slug'            => $plugin->getSlug(),
                'version'         => $plugin->getVersion(),
                'author'          => $plugin->getAuthor(),
                'requires'        => $plugin->getRequires(),
                'tested'          => $plugin->getTested(),
                'rating'          => $plugin->getRating(),
                'num_ratings'     => $plugin->getNumRatings(),
                'active_installs' => $plugin->getActiveInstalls(),
                'last_updated'    => $plugin->getLastUpdated()->format( 'Y-m-d H:i:s' ),
            ];
        }

        return [
            'info'    => [
                'page'    => 1,
                'pages'   => 1,
                'results' => count( $result ),
            ],
            'plugins' => $result,
        ];
    }
}
