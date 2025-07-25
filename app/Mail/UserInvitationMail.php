<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $token;
    public $randomPassword;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\User $user
     * @param string $token
     * @param string $randomPassword
     * @return void
     */
    public function __construct(User $user, string $token, string $randomPassword = null)
    {
        $this->user = $user;
        $this->token = $token;
        $this->randomPassword = $randomPassword;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope()
    {
        return new Envelope(
            subject: 'You have been invited to join our application!',
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content()
    {
        return new Content(
            view: 'emails.new-user-invitation',
            with: [
                'user' => $this->user,
                'token' => $this->token,
                'randomPassword' => $this->randomPassword,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array
     */
    public function attachments()
    {
        return [];
    }
}
