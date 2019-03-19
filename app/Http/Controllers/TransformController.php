<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use mikehaertl\shellcommand\Command;
use Illuminate\Support\Facades\Storage;
use App\Facades\Binary;

class TransformController extends Controller
{
    /**
     * @var \stdClass
     *
     */
    protected $response;

    /**
     * TransformController constructor.
     *
     */
    public function __construct()
    {
        $this->response = new \stdClass();
    }

    /**
     * Process audio file into wave image
     * INPUT: audio file
     * OUTPUT: png file
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function wav2png(Request $request)
    {
        // Check file is uploaded or not
        if(!$request->has('file')) {
            $this->response->result = 0;
            $this->response->message = 'File is required';
            return response()->json($this->response);
        }

        $file = $request->get('file');

        // Check extension is correct or not
        $extension = strpos($file, ".");
        if(!$extension) {
            $this->response->result = 0;
            $this->response->message = 'File is not valid';
            return response()->json($this->response);
        }

        // Link mpg123 lib folder
        $mpg123LibPath = Binary::path('mpg123/lib');
        $mpg123Lib = new Command("ln -s $mpg123LibPath /tmp");

        if(!$mpg123Lib->execute()) {
            $this->response->result = 0;
            $this->response->message = 'Can not create sym link for mpg123 lib';
            $this->response->errors = $mpg123Lib->getError();
            return response()->json($this->response);
        }

        $fileName = substr($file, 0, $extension);

        // Store upload file into local disk
        $mp3File = "$fileName-".time().".mp3";
        try {
            Storage::put($mp3File, Storage::cloud()->get($file));
        } catch (\Exception $exception) {
            $this->response->result = 0;
            $this->response->message = 'Can not put uploaded file into server';
            $this->response->errors = $exception->getMessage();
        }

        // Valide mp3 file is exist or not
        if(!Storage::has($mp3File)) {
            $this->response->result = 0;
            $this->response->message = 'Can not find mp3 file in local disk';
            return response()->json($this->response);
        }

        // Convert mp3 into wav
        $wavFile = "$fileName-".time().".wav";
        $mpg123 = new Command(Binary::path('mpg123/mpg123'));
        $mpg123->addArg('-r', '44100');
        $mpg123->addArg('-w', Storage::path($wavFile));
        $mpg123->addArg(null, Storage::path($mp3File));

        // Check mpg123 is execute error or not
        if(!$mpg123->execute()) {
            $this->response->result = 0;
            $this->response->message = 'mpg123 is not working';
            $this->response->errors = $mpg123->getError();
            return response()->json($this->response);
        }

        // Validate wav file is exist or not
        if(!Storage::has($wavFile)) {
            $this->response->result = 0;
            $this->response->message = 'Can not find wav file in local disk';
            return response()->json($this->response);
        }

        // Convert wav into png
        $pngFile = "$fileName-".time().".png";
        $wav2png = new Command(Binary::path('wav2png/wav2png'));
        $wav2png->addArg('-w', '800');
        $wav2png->addArg('-h', '51');
        $wav2png->addArg('-a', Storage::path($pngFile));
        $wav2png->addArg(null, Storage::path($wavFile));

        // Overwrite default arguments if params is avaliable
        if($request->has('w')) {
            $wav2png->addArg('-w', $request->get('w'));
        }

        if($request->has('h')) {
            $wav2png->addArg('-h', $request->get('h'));
        }

        if($request->has('f')) {
            $wav2png->addArg('-f', $request->get('f'));
        }

        if($request->has('c')) {
            $wav2png->addArg('-c', $request->get('c'));
        }

        // Check wav2png is execute error or not
        if(!$wav2png->execute()) {
            $this->response->result = 0;
            $this->response->message = 'wav2png is not working';
            $this->response->errors = $mpg123->getError();
            return response()->json($this->response);
        }

        // Validate png is exist or not
        if(!Storage::has($pngFile)) {
            $this->response->result = 0;
            $this->response->message = 'Can not find png file in local disk';
            return response()->json($this->response);
        }

        // Drop image and convert to black background
        $magickFile = "$fileName-".time().".png";
        $imagick = new Command(Binary::path('imagemagick/convert'));
        $imagick->addArg(null, Storage::path($pngFile));
        $imagick->addArg('-gravity', 'east');
        $imagick->addArg('-background', 'black');
        $imagick->addArg('-extent', '815x51');
        $imagick->addArg(null, Storage::path($magickFile));

        // Check image magick is execute error or not
        if(!$imagick->execute()) {
            $this->response->result = 0;
            $this->response->message = 'Image magic is not working';
            $this->response->errors = $mpg123->getError();
            return response()->json($this->response);
        }

        // Validate png is exist or not
        if(!Storage::has($magickFile)) {
            $this->response->result = 0;
            $this->response->message = 'Can not find final file in local disk';
            return response()->json($this->response);
        }

        // Put the final result in s3
        try {
            Storage::cloud()->put($magickFile, Storage::get($magickFile));
        } catch (\Exception $exception) {
            $this->response->result = 0;
            $this->response->message = 'Can not put final file into s3';
            $this->response->errors = $exception->getMessage();
        }

        // Finally remove all file in /tmp folder
        shell_exec('rm -rf /tmp/*');

        // Return the png file
        $this->response->result = 1;
        $this->response->file = $magickFile;
        $this->response->link = Storage::cloud()->url($magickFile);
        return response()->json($this->response);
    }

    /**
     * Process image into geometric image
     * INPUT: image.png
     * OUTPUT: image.svg
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function primitive(Request $request)
    {
        // Check file is uploaded or not
        if(!$request->has('file')) {
            $this->response->result = 0;
            $this->response->message = 'File is required';
            return response()->json($this->response);
        }

        $file = $request->get('file');

        // Check extension is correct or not
        $extension = strpos($file, ".");
        if(!$extension) {
            $this->response->result = 0;
            $this->response->message = 'File is not valid';
            return response()->json($this->response);
        }

        $fileName = substr($file, 0, $extension);

        // Store upload file into local disk
        $pngFile = "$fileName-".time().".png";
        try {
            Storage::put($pngFile, Storage::cloud()->get($file));
        } catch (\Exception $exception) {
            $this->response->result = 0;
            $this->response->message = 'Can not put uploaded file into server';
            $this->response->errors = $exception->getMessage();
        }

        // Validate png file is exist or not
        if(!Storage::has($pngFile)) {
            $this->response->result = 0;
            $this->response->message = 'Can not find png file in local disk';
            return response()->json($this->response);
        }

        // Convert image into geogramaphic image
        $svgFile = "$fileName-".time().".svg";
        $primitive = new Command(Binary::path('primitive/primitive'));
        $primitive->addArg('-m', '8');
        $primitive->addArg('-n', '30');
        $primitive->addArg('-i', Storage::path($pngFile));
        $primitive->addArg('-o', Storage::path($svgFile));

        // Overwrite default arguments if params is avaliable
        if($request->has('a')) {
            $primitive->addArg('-a', $request->get('a'));
        }

        if($request->has('bg')) {
            $primitive->addArg('-bg', $request->get('bg'));
        }

        if($request->has('m')) {
            $primitive->addArg('-m', $request->get('m'));
        }

        if($request->has('n')) {
            $primitive->addArg('-n', $request->get('n'));
        }

        if($request->has('nth')) {
            $primitive->addArg('-nth', $request->get('nth'));
        }

        if($request->has('r')) {
            $primitive->addArg('-r', $request->get('r'));
        }

        if($request->has('rep')) {
            $primitive->addArg('-rep', $request->get('rep'));
        }

        if($request->has('s')) {
            $primitive->addArg('-s', $request->get('s'));
        }

        // Check primitive is working or not
        if(!$primitive->execute()) {
            $this->response->result = 0;
            $this->response->message = 'Primitive is not working';
            $this->response->errors = $primitive->getError();
            return response()->json($this->response);
        }

        // Put the final result in s3
        try {
            Storage::cloud()->put($svgFile, Storage::get($svgFile));
        } catch (\Exception $exception) {
            $this->response->result = 0;
            $this->response->message = 'Can not put final file into s3';
            $this->response->errors = $exception->getMessage();
        }

        // Return the svg file
        $this->response->result = 1;
        $this->response->file = $svgFile;
        $this->response->link = Storage::cloud()->url($svgFile);
        return response()->json($this->response);
    }
}
