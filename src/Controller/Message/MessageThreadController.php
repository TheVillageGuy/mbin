<?php declare(strict_types = 1);

namespace App\Controller\Message;

use App\Controller\AbstractController;
use App\DTO\MessageDto;
use App\Entity\MessageThread;
use App\Form\MessageType;
use App\Service\MessageManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MessageThreadController extends AbstractController
{
    public function __construct(
        private MessageManager $manager,
    ) {
    }

    /**
     * @IsGranted("ROLE_USER")
     * @IsGranted("show", subject="thread", statusCode=403)
     */
    public function __invoke(MessageThread $thread, Request $request): Response
    {
        $dto = new MessageDto();

        $form = $this->createForm(MessageType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message = $this->manager->toMessage($dto, $thread, $this->getUserOrThrow());

            return $this->redirectToRoute(
                'user_profile_message',
                ['id' => $thread->getId()]
            );
        }

        $this->manager->readMessages($thread, $this->getUserOrThrow());

        return $this->render(
            'user/profile/message.html.twig',
            [
                'user' => $this->getUserOrThrow(),
                'thread' => $thread,
                'form' => $form->createView(),
            ]
        );
    }
}