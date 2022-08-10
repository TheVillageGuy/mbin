<?php declare(strict_types=1);

namespace App\MessageHandler\ActivityPub;

use App\Message\ActivityPub\ChainActivityMessage;
use App\Repository\ApActivityRepository;
use App\Service\ActivityPub\ApHttpClient;
use App\Service\ActivityPub\Note;
use App\Service\ActivityPub\Page;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ChainActivityHandler implements MessageHandlerInterface
{
    public function __construct(
        private ApHttpClient $client,
        private MessageBusInterface $bus,
        private ApActivityRepository $repository,
        private Note $note,
        private Page $page
    ) {
    }

    public function __invoke(ChainActivityMessage $message)
    {
        if ($message->parent) {
            $this->unloadStack($message->chain, $message->parent);

            return;
        }

        $object = end($message->chain);

        // Handle parent objects
        if ($object['inReplyTo']) {
            $existed = $this->repository->findByObjectId($object['inReplyTo']);

            if ($existed) {
                $this->bus->dispatch(new ChainActivityMessage($message->chain, $existed));
            }

            $message->chain[] = $this->client->getActivityObject($object['inReplyTo']);
            $this->bus->dispatch(new ChainActivityMessage($message->chain));

            return;
        }

        // Create root object
        if ($object['type'] === 'Note') {
            $entity = $this->note->create($object);
        } else {
            $entity = $this->page->create($object);
        }

        array_pop($message->chain);

        $this->bus->dispatch(
            new ChainActivityMessage($message->chain, [
                'id'   => $entity->getId(),
                'type' => get_class($entity),
            ])
        );
    }

    private function unloadStack(array $chain, array $parent): void
    {
        $object = end($chain);

        if ($object['type'] === 'Note') {
            $this->note->create($object);
        } else {
            $this->page->create($object);
        }

        array_pop($chain);

        if (count($chain)) {
            $this->bus->dispatch(new ChainActivityMessage($chain, $parent));
        }
    }
}