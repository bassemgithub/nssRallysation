<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * Images Controller
 *
 * @method \App\Model\Entity\Image[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class ImagesController extends AppController
{
    // Link image type to correct image loader and saver
    // - makes it easier to add additional types later on
    // - makes the function easier to read
    const IMAGE_HANDLERS = [
        IMAGETYPE_JPEG => [
            'load' => 'imagecreatefromjpeg',
            'save' => 'imagejpeg',
            'quality' => 100
        ],
        IMAGETYPE_PNG => [
            'load' => 'imagecreatefrompng',
            'save' => 'imagepng',
            'quality' => 0
        ],
        IMAGETYPE_GIF => [
            'load' => 'imagecreatefromgif',
            'save' => 'imagegif'
        ]
    ];


    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $images = $this->paginate($this->Images);

        if ($this->paginate($this->Images)) {
            $res['status'] = "OK";
            $res['data'] = $images;
            
        }
        else{
                $res['status'] = "KO";
                $res['msg'] = 'This element count be deleted';
        }

        $this->set([
            'my_response' => $res,
            '_serialize' => 'my_response',
        ]);
        $this->RequestHandler->renderAs($this, 'json');
    }

    /**
     * View method
     *
     * @param string|null $id Image id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $image = $this->Images->get($id, [
            'contain' => [],
        ]);

        $this->set(compact('image'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function base64_to_jpeg($base64_string, $output_file) {
        // open the output file for writing
        $ifp = fopen( $output_file, 'wb' ); 
    
        // split the string on commas
        // $data[ 0 ] == "data:image/png;base64"
        // $data[ 1 ] == <actual base64 string>
        $data = explode( ',', $base64_string );
    
        // we could add validation here with ensuring count( $data ) > 1
        fwrite( $ifp, base64_decode( $data[ 1 ] ) );
    
        // clean up the file resource
        fclose( $ifp ); 
    
        return $output_file; 
    }


    public function createThumbnail($src, $dest, $targetWidth, $targetHeight = null) {

        // 1. Load the image from the given $src
        // - see if the file actually exists
        // - check if it's of a valid image type
        // - load the image resource
    
        // get the type of the image
        // we need the type to determine the correct loader
        $type = exif_imagetype($src);
    
        // if no valid type or no handler found -> exit
        if (!$type || !self::IMAGE_HANDLERS[$type]) {
            return null;
        }
    
        // load the image with the correct loader
        $image = call_user_func(self::IMAGE_HANDLERS[$type]['load'], $src);
    
        // no image found at supplied location -> exit
        if (!$image) {
            return null;
        }
    
    
        // 2. Create a thumbnail and resize the loaded $image
        // - get the image dimensions
        // - define the output size appropriately
        // - create a thumbnail based on that size
        // - set alpha transparency for GIFs and PNGs
        // - draw the final thumbnail
    
        // get original image width and height
        $width = imagesx($image);
        $height = imagesy($image);
    
        // maintain aspect ratio when no height set
        if ($targetHeight == null) {
    
            // get width to height ratio
            $ratio = $width / $height;
    
            // if is portrait
            // use ratio to scale height to fit in square
            if ($width > $height) {
                $targetHeight = floor($targetWidth / $ratio);
            }
            // if is landscape
            // use ratio to scale width to fit in square
            else {
                $targetHeight = $targetWidth;
                $targetWidth = floor($targetWidth * $ratio);
            }
        }
    
        // create duplicate image based on calculated target size
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
    
        // set transparency options for GIFs and PNGs
        if ($type == IMAGETYPE_GIF || $type == IMAGETYPE_PNG) {
    
            // make image transparent
            imagecolortransparent(
                $thumbnail,
                imagecolorallocate($thumbnail, 0, 0, 0)
            );
    
            // additional settings for PNGs
            if ($type == IMAGETYPE_PNG) {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }
        }
    
        // copy entire source image to duplicate image and resize
        imagecopyresampled(
            $thumbnail,
            $image,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $width, $height
        );
    
    
        // 3. Save the $thumbnail to disk
        // - call the correct save method
        // - set the correct quality level
    
        // save the duplicate version of the image to disk
        return call_user_func(
            self::IMAGE_HANDLERS[$type]['save'],
            $thumbnail,
            $dest,
            self::IMAGE_HANDLERS[$type]['quality']
        );
    }
    public function getDataURI($img_file) {
        $imgData = base64_encode(file_get_contents($img_file));
        // Format the image SRC:  data:{mime};base64,{data};
        return $src = 'data: '.mime_content_type($img_file).';base64,'.$imgData;
    }
    public function add()
    {
   
        $res = array();
        $uploadPath = "../src/uploads/";
        $request = json_decode($this->request->input());

        if (!file_exists("../src/uploads/full")) {
            mkdir("../src/uploads/full", 0777, true);
        }
        if (!file_exists("../src/uploads/mid")) {
            mkdir("../src/uploads/mid", 0777, true);
        }
        if (!file_exists("../src/uploads/thumbnails")) {
            mkdir("../src/uploads/thumbnails", 0777, true);
        }    

        if(strlen($request->name)<6){
            $res['status'] = "OK";
            $res['msg'] = 'Image length name should be greather than 5';
            $this->set([
                'my_response' => $res,
                '_serialize' => 'my_response',
            ]);
            $this->RequestHandler->renderAs($this, 'json');
        }
        else{
            /*
            75 x 100	0.75	Flickr thumbnail
            180 x 240	0.75	Flickr small
            375 x 500	0.75	Flickr medium
            768 x 1024	0.75	Flickr large
            */

            // create images
            file_put_contents($uploadPath.'full/'.$request->name.'.jpeg', file_get_contents($request->data));
            $this->createThumbnail($uploadPath.'full/'.$request->name.'.jpeg', $uploadPath.'thumbnails/'.$request->name.'.jpeg', 50, 50);
            $this->createThumbnail($uploadPath.'full/'.$request->name.'.jpeg', $uploadPath.'mid/'.$request->name.'.jpeg', 100, 100);
            
            // create uri image 
            $base64_mid = $this->getDataURI($uploadPath.'mid/'.$request->name.'.jpeg');
            $base64_thumbnails = $this->getDataURI($uploadPath.'thumbnails/'.$request->name.'.jpeg');
            $image = $this->Images->newEmptyEntity();
            $image->name = $request->name;
            $image->full = $request->data;
            $image->mid = $base64_mid;
            $image->thumbnails = $base64_thumbnails;
            if ($this->request->is('post')) {


                    $image = $this->Images->patchEntity($image, $this->request->getData());
                    $reult = $this->Images->save($image);

                    if ($this->Images->save($image)) {
                        $res['status'] = "OK";
                        $res['msg'] = 'The record have been saved';
                        
                    }
                    else{
                        $res['status'] = "KO";
                        $res['msg'] = 'The record could not be saved. Please, try again';
                        
                    }
                }

                $this->set([
                    'my_response' => $res,
                    '_serialize' => 'my_response',
                ]);
                $this->RequestHandler->renderAs($this, 'json');
            }
    }

    /**
     * Edit method
     *
     * @param string|null $id Image id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        //$request = json_decode($this->request->input());
        // recover body data
        $request = $this->request->input('json_decode');
        $res = array();
        $uploadPath = "../src/uploads/";

        if (!file_exists("../src/uploads/full")) {
            mkdir("../src/uploads/full", 0777, true);
        }
        if (!file_exists("../src/uploads/mid")) {
            mkdir("../src/uploads/mid", 0777, true);
        }
        if (!file_exists("../src/uploads/thumbnails")) {
            mkdir("../src/uploads/thumbnails", 0777, true);
        }
       


        $image = $this->Images->get($id, [
            'contain' => [],
        ]);

        if(strlen($request->name)<6){
            $res['status'] = "KO";
            $res['msg'] = 'Image length name should be greather than 5';
            $this->set([
                'my_response' => $res,
                '_serialize' => 'my_response',
            ]);
            $this->RequestHandler->renderAs($this, 'json');
        }
        else{
            // remove the images to be edit
            if (file_exists($uploadPath.'full/'.$image->name.'.jpeg')) {
                unlink($uploadPath.'full/'.$image->name.'.jpeg');  
            }
            if (file_exists($uploadPath.'mid/'.$image->name.'.jpeg')) {
                unlink($uploadPath.'mid/'.$image->name.'.jpeg');    
            }
            if (file_exists($uploadPath.'thumbnails/'.$image->name.'.jpeg')) {
                unlink($uploadPath.'thumbnails/'.$image->name.'.jpeg');   
            }

            // create the new images edited 
            file_put_contents($uploadPath.'full/'.$request->name.'.jpeg', file_get_contents($request->data));
            $this->createThumbnail($uploadPath.'full/'.$request->name.'.jpeg', $uploadPath.'thumbnails/'.$request->name.'.jpeg', 50, 50);
            $this->createThumbnail($uploadPath.'full/'.$request->name.'.jpeg', $uploadPath.'mid/'.$request->name.'.jpeg', 100, 100);
            
            $base64_mid = $this->getDataURI($uploadPath.'mid/'.$request->name.'.jpeg');
            $base64_thumbnails = $this->getDataURI($uploadPath.'thumbnails/'.$request->name.'.jpeg');

            $image->name = $request->name;
            $image->full = $request->data;
            $image->mid = $base64_mid;
            $image->thumbnails = $base64_thumbnails;
            if ($this->request->is(['patch', 'post', 'put'])) {


                $image = $this->Images->patchEntity($image, $this->request->getData());
                $reult = $this->Images->save($image);

                if ($this->Images->save($image)) {
                    $res['status'] = "OK";
                    $res['msg'] = 'The record have been eited';
                    
                }
                else{
                    $res['status'] = "KO";
                    $res['msg'] = 'The record could not be edited. Please, try again';
                    
                }
            }

            $this->set([
                'my_response' => $res,
                '_serialize' => 'my_response',
            ]);
            $this->RequestHandler->renderAs($this, 'json');
        }
    }

    /**
     * Delete method
     *
     * @param string|null $id Image id.
     * @return \Cake\Http\Response|null|void Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $uploadPath = "../src/uploads/";
        $this->request->allowMethod(['post', 'delete']);
        try{
            $image= $this->Images->get($id);
        }
        catch(Exception $e) {
            $res['status'] = "KO";
            $res['msg'] = 'Resources not exist';
            $this->set([
                'my_response' => $res,
                '_serialize' => 'my_response',
            ]);
            $this->RequestHandler->renderAs($this, 'json');
            exit();
        }
        if (file_exists($uploadPath.'full/'.$image->name.'.jpeg')) {
            unlink($uploadPath.'full/'.$image->name.'.jpeg');  
        }
        if (file_exists($uploadPath.'mid/'.$image->name.'.jpeg')) {
            unlink($uploadPath.'mid/'.$image->name.'.jpeg');    
        }
        if (file_exists($uploadPath.'thumbnails/'.$image->name.'.jpeg')) {
            unlink($uploadPath.'thumbnails/'.$image->name.'.jpeg');   
        }     
        if ($this->Images->delete($image)) {
                $res['status'] = "OK";
                $res['msg'] = 'This element have bene deleted';
                
        }
        else{
                $res['status'] = "KO";
                $res['msg'] = 'This element count be deleted';
        }

        $this->set([
            'my_response' => $res,
            '_serialize' => 'my_response',
        ]);
        $this->RequestHandler->renderAs($this, 'json');

    }
}
