<?php

namespace App\Http\Controllers;

use FFMpeg\FFMpeg;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\SpeechClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'video' => 'required|mimes:mp4,avi,mkv|max:20480', // Limite de 20MB
        ]);

        // Salvar o vídeo
        $videoPath = $request->file('video')->store('videos');
        $audioPath = $this->extractAudio(storage_path('app/' . $videoPath));

        // Transcrever o áudio e salvar como legenda
        $srtPath = $this->generateSubtitle($audioPath, pathinfo($videoPath, PATHINFO_FILENAME));

        return response()->download($srtPath)->deleteFileAfterSend(true);
    }

    private function extractAudio($videoPath)
    {
        // Extrair o áudio do vídeo usando FFMpeg
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($videoPath);

        $audioPath = storage_path('app/audios/audio.wav');
        $video->save(new \FFMpeg\Format\Audio\Wav(), $audioPath);

        return $audioPath;
    }

    private function generateSubtitle($audioPath, $fileName)
    {
        // Configurar o cliente Google Speech-to-Text
        $client = new SpeechClient();
        $audioContent = file_get_contents($audioPath);
        $audio = (new RecognitionAudio())->setContent($audioContent);
        $config = (new RecognitionConfig())
            ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
            ->setLanguageCode('en-US')
            ->setEnableAutomaticPunctuation(true);

        // Enviar áudio para transcrição
        $response = $client->recognize($config, $audio);
        $srtPath = storage_path('app/subtitles/' . $fileName . '.srt');
        $this->saveTranscriptionAsSrt($response, $srtPath);

        return $srtPath;
    }

    private function saveTranscriptionAsSrt($response, $srtPath)
    {
        // Escrever o arquivo SRT
        $srtFile = fopen($srtPath, 'w');
        $index = 1;

        foreach ($response->getResults() as $result) {
            $alternative = $result->getAlternatives()[0];
            $transcript = $alternative->getTranscript();

            fwrite($srtFile, "$index\n");
            fwrite($srtFile, "00:00:00,000 --> 00:00:02,000\n"); // Exemplo de timestamp, ajustar conforme necessário
            fwrite($srtFile, "$transcript\n\n");

            $index++;
        }

        fclose($srtFile);
    }
}
