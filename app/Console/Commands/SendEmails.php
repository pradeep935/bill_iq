<?php

namespace App\Console\Commands;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

use App\Models\MailQueue;
use Illuminate\Console\Command;

class SendEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Emails';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $mail = new PHPMailer;
        $mail->IsSMTP();
        $mail->SMTPDebug = false;
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = "tls";
        
        $mail->Port       = env("MAIL_PORT");
        $mail->Host       = env("MAIL_HOST");
        $mail->Username   = env("MAIL_USERNAME");
        $mail->Password   = env("MAIL_PASSWORD");

        $mail->setFrom(env('MAIL_FROM_ADDRESS'), 'AIFF AMS');

        $mail->IsHTML(true);
        
        $loop_expiry_time = time() + 56;
        $loop_count = 0;

        while ( time() < $loop_expiry_time ) {

            $flag_send_gmail = false;

            $mail_queue = MailQueue::where("solved",0)->where("priority",0)->limit(5)->orderBy("id","DESC")->get();

            foreach ($mail_queue as $mail_item) {

                $mail_item->solved = -2;
                $mail_item->save();

                $content = $mail_item->content;

                $mail->Subject = $mail_item->subject;
                $mail->Body = $content;

                

                if($mail_item->mailto){
                    $emails = explode(',', $mail_item->mailto);
                    foreach ($emails as $email) {
                        $email = trim($email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)){
                            $mail->AddAddress($email);
                        }
                    }
                }

                if($mail_item->mailcc){
                    $emails = explode(',', $mail_item->mailcc);
                    foreach ($emails as $email) {
                        $email = trim($email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)){
                            $mail->AddCC($email);
                        }
                    }
                }

                if($mail_item->mailbcc){
                    $emails = explode(',', $mail_item->mailbcc);
                    foreach ($emails as $email) {
                        $email = trim($email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)){
                            $mail->AddBCC($email);
                        }
                    }
                }

                if($mail_item->at_folder){
                    if($mail_item->at_file){
                        $mail->addAttachment('/var/www/html/'.$mail_item->at_folder.'/'.$mail_item->at_file, $mail_item->at_file);
                    }
                }

                if(!$mail->Send()) {
                    $mail_item->solved = -1;
                    $mail_item->remarks = $mail->ErrorInfo;
                    $mail_item->save();
                    $this->info("Mailer Error: " . $mail->ErrorInfo);
                } else {
                    $mail_item->solved = 1;
                    $mail_item->save();
                }

                $mail->ClearAllRecipients();
                $mail->ClearAttachments();
                $mail->ClearCustomHeaders();
            }

            sleep(2);
            $this->info("loop count ".$loop_count++);
            $this->info("emails sent - ".sizeof($mail_queue));
            
        }
    }
}
