<?php

/*
 * This file is part of the Shel.Neos.Orphanage package.
 *
 * (c) 2024 Sebastian Helzle <sebastian@helzle.it>
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\Orphanage\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Service\ContentContextFactory;

#[Flow\Scope('singleton')]
final class OrphanageController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly ContentContextFactory $contentContextFactory
    ) {
    }

    public function indexAction(
        string $workspaceName = 'live',
        string $nodeTypeFilter = null,
        int $currentPage = 1
    ): void {
        $limit = 20;
        $totalOrphanNodes = $this->getNumberOfOrphanedNodes($workspaceName, $nodeTypeFilter);
        $maxPages = (int)ceil($totalOrphanNodes / $limit);
        if ($currentPage > $maxPages) {
            $currentPage = 1;
        }

        $offset = ($currentPage - 1) * $limit;
        $nodeTypeFilter = $nodeTypeFilter ?: null;

        $orphanNodes = $this->findOrphanNodes($workspaceName, $nodeTypeFilter, $offset, $limit);

        $orphanData = array_map(function (NodeData $orphanNode) {
            $nodeType = $orphanNode->getNodeType();
            return [
                'path' => $orphanNode->getPath(),
                'nodeType' => $nodeType->getName(),
                'workspace' => $orphanNode->getWorkspace()->getName(),
                'childNodes' => $this->getNumberOfChildNodesFor($orphanNode),
            ];
        }, $orphanNodes);

        $filterableNodeTypes = $this->getOrphanNodeTypes($workspaceName);
        sort($filterableNodeTypes);

        $this->view->assignMultiple([
            'totalOrphanNodes' => $totalOrphanNodes,
            'orphanNodes' => $orphanData,
            'filterableNodeTypes' => $filterableNodeTypes,
            'nodeTypeFilter' => $nodeTypeFilter,
            'pageCount' => (int)ceil($totalOrphanNodes / $limit),
            'currentPage' => $currentPage,
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    public function removeOrphanNodeAction(string $nodePath, string $workspaceName): void
    {
        $this->removeNodeAndChildNodesInWorkspaceByPath($nodePath, $workspaceName);
        $this->addFlashMessage(sprintf('Removed orphan node in path "%s"', $nodePath));
    }

    public function adoptOrphanNodeAction(string $nodePath, string $workspaceName): void
    {
        $context = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        // Retrieve first orphanage node to store orphaned nodes
        /** @var NodeInterface|null $parentNode */
        $parentNode = (new FlowQuery([$context->getCurrentSiteNode()]))
            ->find('[instanceof Shel.Neos.Orphanage:Document.Orphanage]')->get(0);

        if (!$parentNode) {
            $this->addFlashMessage(
                'Please create one first in your document tree',
                'No orphanage node found',
                Message::SEVERITY_ERROR
            );
            $this->throwStatus(404);
        }

        $node = $context->getNode($nodePath);
        if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
            $node->moveInto($parentNode);
            $this->addFlashMessage(sprintf('Adopted document node "%s" into orphanage', $nodePath));
        } else {
            $node->moveInto($parentNode->getNode('orphans'));
            $this->addFlashMessage(sprintf('Adopted content node "%s" into orphanage', $nodePath));
        }
    }

    /**
     * Removes all nodes with a specific path and their children in the given workspace.
     */
    protected function removeNodeAndChildNodesInWorkspaceByPath(string $nodePath, string $workspaceName): void
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder
            ->resetDQLParts()
            ->delete(NodeData::class, 'n')
            ->where('n.path LIKE :path')
            ->orWhere('n.path LIKE :subpath')
            ->andWhere('n.workspace = :workspace')
            ->setParameters([
                'path' => $nodePath,
                'subpath' => $nodePath . '/%',
                'workspace' => $workspaceName
            ])
            ->getQuery()
            ->execute();
    }

    protected function getNumberOfOrphanedNodes(string $workspaceName = 'live', string $nodeTypeFilter = null): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $workspaceList = [];
        $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);
        while ($workspace !== null) {
            $workspaceList[] = $workspace->getName();
            $workspace = $workspace->getBaseWorkspace();
        }

        $query = $queryBuilder
            ->select('COUNT(n)')
            ->from(NodeData::class, 'n')
            ->leftJoin(
                NodeData::class,
                'n2',
                Join::WITH,
                'n.parentPathHash = n2.pathHash AND n2.workspace IN (:workspaceList)'
            )
            ->where('n2.path IS NULL')
            ->andWhere($queryBuilder->expr()->not('n.path = :slash'))
            ->andWhere('n.workspace = :workspace');

        $parameters = [
            'workspaceList' => $workspaceList,
            'slash' => '/',
            'workspace' => $workspaceName,
        ];

        if ($nodeTypeFilter !== null) {
            $query->andWhere('n.nodeType = :nodetype');
            $parameters['nodetype'] = $nodeTypeFilter;
        }

        return $query
            ->setParameters($parameters)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function findOrphanNodes(
        string $workspaceName = 'live',
        string $nodeTypeFilter = null,
        int $offset = 0,
        int $limit = null
    ): array {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $workspaceList = [];
        $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);
        while ($workspace !== null) {
            $workspaceList[] = $workspace->getName();
            $workspace = $workspace->getBaseWorkspace();
        }

        $query = $queryBuilder
            ->select('n')
            ->from(NodeData::class, 'n')
            ->leftJoin(
                NodeData::class,
                'n2',
                Join::WITH,
                'n.parentPathHash = n2.pathHash AND n2.workspace IN (:workspaceList)'
            )
            ->where('n2.path IS NULL')
            ->andWhere($queryBuilder->expr()->not('n.path = :slash'))
            ->andWhere('n.workspace = :workspace')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('n.path');

        $parameters = [
            'workspaceList' => $workspaceList,
            'slash' => '/',
            'workspace' => $workspaceName,
        ];

        if ($nodeTypeFilter !== null) {
            $query->andWhere('n.nodeType = :nodetype');
            $parameters['nodetype'] = $nodeTypeFilter;
        }

        return $query
            ->setParameters($parameters)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return string[]
     */
    protected function getOrphanNodeTypes(string $workspaceName): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $workspaceList = [];
        $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);
        while ($workspace !== null) {
            $workspaceList[] = $workspace->getName();
            $workspace = $workspace->getBaseWorkspace();
        }

        $query = $queryBuilder
            ->select('DISTINCT n.nodeType')
            ->from(NodeData::class, 'n')
            ->leftJoin(
                NodeData::class,
                'n2',
                Join::WITH,
                'n.parentPathHash = n2.pathHash AND n2.workspace IN (:workspaceList)'
            )
            ->where('n2.path IS NULL')
            ->andWhere($queryBuilder->expr()->not('n.path = :slash'))
            ->andWhere('n.workspace = :workspace');

        $parameters = [
            'workspaceList' => $workspaceList,
            'slash' => '/',
            'workspace' => $workspaceName,
        ];

        return $query
            ->setParameters($parameters)
            ->getQuery()
            ->getSingleColumnResult();
    }

    protected function getNumberOfChildNodesFor(NodeData $orphanNode): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        return (int)$queryBuilder
            ->select('COUNT(n)')
            ->from(NodeData::class, 'n')
            ->where('n.path LIKE :path')
            ->andWhere('n.workspace = :workspace')
            ->setParameters([
                    'path' => $orphanNode->getPath() . '/%',
                    'workspace' => $orphanNode->getWorkspace()->getName()
                ]
            )
            ->getQuery()
            ->getSingleScalarResult();
    }

}
