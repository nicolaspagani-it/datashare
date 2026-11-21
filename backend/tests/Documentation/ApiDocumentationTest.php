<?php

namespace App\Tests\Documentation;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Garde-fous de la documentation OpenAPI : elle ne doit jamais s'écarter de l'API réelle.
 *
 *  - toutes les routes /api sont documentées, avec les bons cadenas (authentification)
 *  - la spécification est cohérente (références valides, résumés, réponses)
 *  - le fichier docs/openapi.yaml du dépôt est à jour
 *
 * Quand l'un de ces tests échoue après un changement de l'API : relancer `composer docs`.
 */
class ApiDocumentationTest extends WebTestCase
{
    /** Les seules opérations protégées par un jeton JWT (cadenas dans Swagger). */
    private const SECURED = ['GET /api/me', 'GET /api/files', 'DELETE /api/files/{id}'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return array<string, mixed> la spécification, telle que la sert GET /api/doc.json */
    private function spec(): array
    {
        $this->client->request('GET', '/api/doc.json');
        $this->assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Toutes les opérations de la spécification, sous la forme "GET /api/me" => définition.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, array<string, mixed>>
     */
    private function operations(array $spec): array
    {
        $operations = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $definition) {
                $operations[strtoupper($method).' '.$path] = $definition;
            }
        }

        return $operations;
    }

    // ------------------------------------------------------------------ la spécification est servie

    public function testSpecIsPublicJsonWithTheApiInformation(): void
    {
        // Pas de jeton : la documentation est publique.
        $spec = $this->spec();

        $this->assertStringContainsString('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertSame('DataShare API', $spec['info']['title']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $spec['info']['version']);
        $this->assertStringStartsWith('3.', $spec['openapi']);
    }

    public function testSwaggerUiPageIsServedAndLoadsTheSpecification(): void
    {
        $page = file_get_contents(\dirname(__DIR__, 2).'/public/docs/index.html');

        $this->assertStringContainsString('../api/doc.json', $page, 'La page doit lire la spécification servie par l\'API.');
        $this->assertStringContainsString('swagger-ui-bundle.js', $page);

        // Les fichiers de Swagger UI sont installés par "composer install" (assets:install) dans public/bundles/.
        $assets = \dirname(__DIR__, 2).'/public/bundles/nelmioapidoc/swagger-ui';
        $this->assertFileExists($assets.'/swagger-ui-bundle.js', 'Lancez `php bin/console assets:install public`.');
        $this->assertFileExists($assets.'/swagger-ui.css');
    }

    // ------------------------------------------------------------------ toutes les routes sont documentées

    public function testEveryApiRouteIsDocumented(): void
    {
        $documented = array_keys($this->operations($this->spec()));
        $missing = [];

        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            // On ne cherche que les routes de l'API (pas la documentation elle-même ni les routes internes de Symfony)
            if (!str_starts_with($route->getPath(), '/api') || $name === 'app.swagger') {
                continue;
            }
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $operation = $method.' '.$route->getPath();
                if (!\in_array($operation, $documented, true)) {
                    $missing[] = $operation;
                }
            }
        }

        $this->assertSame([], $missing, "Routes non documentées : ajoutez des attributs #[OA\\...] puis relancez `composer docs`.");
    }

    public function testNothingIsDocumentedThatDoesNotExist(): void
    {
        $routes = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $routes[] = $method.' '.$route->getPath();
            }
        }
        // /api/login est géré par le pare-feu : il a une route (config/routes.yaml) mais pas de contrôleur.
        $this->assertSame([], array_values(array_diff(array_keys($this->operations($this->spec())), $routes)));
    }

    // ------------------------------------------------------------------ authentification : le cadenas dit vrai

    public function testOnlyTheExpectedOperationsAreMarkedAsSecured(): void
    {
        $secured = [];
        foreach ($this->operations($this->spec()) as $operation => $definition) {
            if (!empty($definition['security'])) {
                $secured[] = $operation;
                $this->assertSame([['Bearer' => []]], $definition['security'], "$operation doit demander un jeton Bearer.");
            }
        }
        sort($secured);
        $expected = self::SECURED;
        sort($expected);

        $this->assertSame($expected, $secured);
    }

    public function testDocumentedAuthenticationMatchesTheRealSecurity(): void
    {
        // Sans jeton : une route documentée avec cadenas doit répondre 401, et les autres JAMAIS 401.
        foreach ($this->operations($this->spec()) as $operation => $definition) {
            [$method, $path] = explode(' ', $operation, 2);
            $path = str_replace(['{id}', '{token}'], ['1', 'AAAA1111'], $path);

            $this->client->request($method, $path, server: ['HTTP_ACCEPT' => 'application/json']);
            $status = $this->client->getResponse()->getStatusCode();

            if (\in_array($operation, self::SECURED, true)) {
                $this->assertSame(401, $status, "$operation est documentée avec un cadenas : elle doit refuser un visiteur.");
            } else {
                $this->assertNotSame(401, $status, "$operation est documentée comme publique : elle ne doit pas exiger de jeton.");
            }
        }
    }

    public function testSecuredOperationsDocumentTheAuthenticationError(): void
    {
        $operations = $this->operations($this->spec());

        foreach (self::SECURED as $operation) {
            $this->assertArrayHasKey('401', $operations[$operation]['responses'], "$operation doit documenter l'erreur 401.");
        }
    }

    // ------------------------------------------------------------------ cohérence de la spécification

    public function testEveryOperationHasASummaryATagAndResponses(): void
    {
        foreach ($this->operations($this->spec()) as $operation => $definition) {
            $this->assertNotEmpty($definition['summary'] ?? '', "$operation : résumé manquant.");
            $this->assertNotEmpty($definition['tags'] ?? [], "$operation : groupe (tag) manquant.");
            $this->assertNotEmpty($definition['responses'] ?? [], "$operation : aucune réponse documentée.");
            foreach ($definition['responses'] as $code => $response) {
                $this->assertNotEmpty($response['description'] ?? '', "$operation, réponse $code : description manquante.");
            }
        }
    }

    public function testEveryOperationTagIsDeclared(): void
    {
        $spec = $this->spec();
        $declared = array_column($spec['tags'], 'name');

        foreach ($this->operations($spec) as $operation => $definition) {
            foreach ($definition['tags'] as $tag) {
                $this->assertContains($tag, $declared, "$operation utilise le groupe \"$tag\", qui n'est pas déclaré.");
            }
        }
    }

    public function testEveryReferenceResolvesToAnExistingSchema(): void
    {
        $spec = $this->spec();
        $references = [];
        array_walk_recursive($spec, static function ($value, $key) use (&$references): void {
            if ($key === '$ref') {
                $references[] = $value;
            }
        });

        $this->assertNotEmpty($references);
        foreach (array_unique($references) as $reference) {
            $this->assertStringStartsWith('#/components/schemas/', $reference);
            $name = substr($reference, \strlen('#/components/schemas/'));
            $this->assertArrayHasKey($name, $spec['components']['schemas'], "Référence cassée : $reference");
        }
    }

    public function testNoSchemaIsLeftUnused(): void
    {
        $spec = $this->spec();
        $used = [];
        array_walk_recursive($spec, static function ($value, $key) use (&$used): void {
            if ($key === '$ref') {
                $used[] = substr($value, \strlen('#/components/schemas/'));
            }
        });

        // Un schéma jamais utilisé est du bruit dans la documentation (ex : un doublon généré automatiquement).
        $this->assertSame([], array_values(array_diff(array_keys($spec['components']['schemas']), $used)));
    }

    public function testErrorSchemasMatchTheRealErrorFormat(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        $this->assertSame(['message'], $schemas['Error']['required']);
        $this->assertSame(['code', 'message'], $schemas['AuthError']['required']);
        $this->assertArrayHasKey('violations', $schemas['ValidationError']['properties']);
        $this->assertSame(['propertyPath', 'title'], $schemas['Violation']['required']);
    }

    public function testUploadFormDocumentsTheSevenDurations(): void
    {
        $body = $this->operations($this->spec())['POST /api/files']['requestBody']['content']['multipart/form-data']['schema'];

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], $body['properties']['expiration']['enum']);
        $this->assertSame(['file', 'expiration'], $body['required']);
        $this->assertSame('binary', $body['properties']['file']['format']);
    }

    // ------------------------------------------------------------------ le fichier du dépôt est à jour

    public function testExportedFileMatchesTheCurrentSpecification(): void
    {
        $file = \dirname(__DIR__, 3).'/docs/openapi.yaml';
        $this->assertFileExists($file, 'Lancez `composer docs` pour créer docs/openapi.yaml.');

        $exported = Yaml::parseFile($file);

        $this->assertEquals(
            $this->spec(),
            $exported,
            'docs/openapi.yaml est périmé : relancez `composer docs` (dans backend/) et commitez le fichier.',
        );
    }
}
