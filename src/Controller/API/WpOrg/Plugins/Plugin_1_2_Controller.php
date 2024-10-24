<?php

namespace App\Controller\API\WpOrg\Plugins;

use App\Service\WpOrg\Plugin_1_2_API_Service;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class Plugin_1_2_Controller extends AbstractController {

    public function __construct( private readonly Plugin_1_2_API_Service $ApiService ) {
        //
    }

    #[Route( '/plugins/info/1.2', name: 'plugins_api_info', methods: [ 'GET' ] )]
    public function getInfo( Request $request ): JsonResponse {
        $action = $request->query->get( 'action' );
        $slug   = $request->query->get( 'slug' );

        return match ( $action ) {
            'plugin_information' => $this->handlePluginInformation( $slug ),
            'query_plugins' => $this->handleQueryPlugins(),
            default => new JsonResponse( [ 'error' => 'Invalid action' ], Response::HTTP_BAD_REQUEST ),
        };
    }

    private function handlePluginInformation( ?string $slug ): JsonResponse {
        if ( ! $slug ) {
            return new JsonResponse( [ 'error' => 'Slug is required' ], Response::HTTP_BAD_REQUEST );
        }

        try {
            $pluginInfo = $this->ApiService->getPluginInformation( $slug );

            return new JsonResponse( $pluginInfo );
        } catch ( \Exception $e ) {
            return new JsonResponse( [ 'error' => $e->getMessage() ], Response::HTTP_NOT_FOUND );
        }
    }

    private function handleQueryPlugins(): JsonResponse {
        try {
            $plugins = $this->ApiService->queryPlugins();

            return new JsonResponse( $plugins );
        } catch ( \Exception $e ) {
            return new JsonResponse( [ 'error' => $e->getMessage() ], Response::HTTP_INTERNAL_SERVER_ERROR );
        }
    }
}
