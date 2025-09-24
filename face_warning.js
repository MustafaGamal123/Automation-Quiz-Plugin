// D:\Quiz_Proctoring\server\moodle\mod\quiz\accessrule\proctoring\face_warning.js
(function() {
    'use strict';
   
    let warningElement = null;
    let lastActivity = Date.now();
    let isQuizActive = false;
    let videoElement = null;
    let canvasElement = null;
    let canvasContext = null;
    let faceDetectionActive = false;
    let previousFacePosition = null;
    let movementThreshold = 57;
    let noFaceDetectedCount = 0;
    let maxNoFaceCount = 11;
    let faceHistory = [];
    let historySize = 10; 
    let suspiciousActivityLog = [];
    let suspiciousImages = [];
    let imageCounter = 0;
    let eyeButton = null;
    let modalElement = null;
    let quizId = null;
    let quizFinished = false;
    let lastWarningTime = 0;
    let warningCooldown = 7000;
    let lastDetectionTime = 0;
    let detectionCooldown = 1000;
    
    let faceApiLoaded = false;
    let faceApiModels = null;
    
    const FACE_TURN_THRESHOLD = 25; 
    let faceTurnedAwayCount = 0;
    let maxFaceTurnedCount = 8;
    
    let memoryCleanupInterval = null;
    let detectionFrameBuffer = [];
    const MAX_FRAME_BUFFER = 5;
    let lastMemoryCleanup = Date.now();
    const MEMORY_CLEANUP_INTERVAL = 30000; 

    function loadFaceApiLibrary() {
        return new Promise((resolve, reject) => {
            if (window.faceapi) {
                faceApiLoaded = true;
                resolve();
                return;
            }
            
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/face-api.js/0.22.2/face-api.min.js';
            script.onload = () => {
                faceApiLoaded = true;
                resolve();
            };
            script.onerror = () => {
                console.warn('Face-API.js failed to load, falling back to basic detection');
                faceApiLoaded = false;
                resolve(); 
            };
            document.head.appendChild(script);
        });
    }

    async function loadFaceApiModels() {
        if (!faceApiLoaded || !window.faceapi) return false;
        
        try {
            const modelPath = '/mod/quiz/accessrule/proctoring/models';
            
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(modelPath).catch(() => null),
                faceapi.nets.faceLandmark68Net.loadFromUri(modelPath).catch(() => null),
                faceapi.nets.faceRecognitionNet.loadFromUri(modelPath).catch(() => null)
            ]);
            
            faceApiModels = true;
            return true;
        } catch (error) {
            console.warn('Face-API models failed to load:', error);
            faceApiModels = false;
            return false;
        }
    }

    function calculateFaceOrientation(landmarks) {
        if (!landmarks) return { yaw: 0, turned: false };
        
        try {
            const nose = landmarks.getNose();
            const leftEye = landmarks.getLeftEye();
            const rightEye = landmarks.getRightEye();
            const jaw = landmarks.getJawOutline();
            
            if (!nose || !leftEye || !rightEye || !jaw) {
                return { yaw: 0, turned: false };
            }
            
            const leftEyeCenter = {
                x: leftEye.reduce((sum, p) => sum + p.x, 0) / leftEye.length,
                y: leftEye.reduce((sum, p) => sum + p.y, 0) / leftEye.length
            };
            
            const rightEyeCenter = {
                x: rightEye.reduce((sum, p) => sum + p.x, 0) / rightEye.length,
                y: rightEye.reduce((sum, p) => sum + p.y, 0) / rightEye.length
            };
            
            const noseBottom = nose[3] || nose[0];
            
            const noseToLeftEye = Math.abs(noseBottom.x - leftEyeCenter.x);
            const noseToRightEye = Math.abs(noseBottom.x - rightEyeCenter.x);
            
            const eyeDistanceRatio = noseToLeftEye / (noseToRightEye + 1);
            const yawAngle = (eyeDistanceRatio - 1) * 45; 
            
            const isTurnedAway = Math.abs(yawAngle) > FACE_TURN_THRESHOLD || 
                                eyeDistanceRatio > 1.8 || eyeDistanceRatio < 0.55;
            
            return {
                yaw: yawAngle,
                turned: isTurnedAway,
                leftEyeDistance: noseToLeftEye,
                rightEyeDistance: noseToRightEye,
                ratio: eyeDistanceRatio
            };
        } catch (error) {
            return { yaw: 0, turned: false };
        }
    }

    async function detectFaceWithApi(videoElement) {
        if (!faceApiLoaded || !faceApiModels || !window.faceapi) {
            return detectFaceBasic();
        }
        
        try {
            const detection = await faceapi
                .detectSingleFace(videoElement, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks();
            
            if (detection) {
                const box = detection.detection.box;
                const landmarks = detection.landmarks;
                
                const orientation = calculateFaceOrientation(landmarks);
                
                return {
                    x: box.x,
                    y: box.y,
                    width: box.width,
                    height: box.height,
                    score: detection.detection.score,
                    landmarks: landmarks,
                    orientation: orientation,
                    faceApiDetection: true
                };
            }
            
            return null;
        } catch (error) {
            return detectFaceBasic();
        }
    }

    function detectFaceBasic() {
        if (!canvasContext || !videoElement) return null;
        
        try {
            canvasContext.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
            const imageData = canvasContext.getImageData(0, 0, canvasElement.width, canvasElement.height);
            return detectFace(imageData);
        } catch (error) {
            return null;
        }
    }

    function performMemoryCleanup() {
        try {
            detectionFrameBuffer = [];
            
            if (faceHistory.length > historySize * 2) {
                faceHistory = faceHistory.slice(-historySize);
            }
            
            if (suspiciousImages.length > 50) {
                const recentImages = suspiciousImages.slice(-25);
                suspiciousImages = recentImages;
                updateLocalStorage();
            }
            
            if (canvasContext) {
                canvasContext.clearRect(0, 0, canvasElement.width, canvasElement.height);
            }
            
            if (window.gc && typeof window.gc === 'function') {
                window.gc();
            }
            
            lastMemoryCleanup = Date.now();
            
        } catch (error) {
            console.warn('Memory cleanup error:', error);
        }
    }

    function startMemoryCleanup() {
        if (memoryCleanupInterval) {
            clearInterval(memoryCleanupInterval);
        }
        
        memoryCleanupInterval = setInterval(() => {
            performMemoryCleanup();
        }, MEMORY_CLEANUP_INTERVAL);
    }

    function stopMemoryCleanup() {
        if (memoryCleanupInterval) {
            clearInterval(memoryCleanupInterval);
            memoryCleanupInterval = null;
        }
    }

    function playWarningSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(440, audioContext.currentTime);
            oscillator.frequency.exponentialRampToValueAtTime(880, audioContext.currentTime + 0.2);
            oscillator.frequency.exponentialRampToValueAtTime(440, audioContext.currentTime + 0.4);
            oscillator.frequency.exponentialRampToValueAtTime(660, audioContext.currentTime + 0.6);
            
            gainNode.gain.setValueAtTime(0.4, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.1, audioContext.currentTime + 0.3);
            gainNode.gain.exponentialRampToValueAtTime(0.3, audioContext.currentTime + 0.4);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.8);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.8);
            
            setTimeout(() => {
                try {
                    audioContext.close();
                } catch (e) {}
            }, 1000);
        } catch (error) {
            console.warn('Audio context error:', error);
        }
    }

    function getQuizId() {
        const match = window.location.href.match(/attempt\.php\?id=(\d+)/);
        return match ? match[1] : 'global';
    }

    function createEyeButton() {
        if (eyeButton) return;
        
        eyeButton = document.createElement('div');
        eyeButton.id = 'suspicious-images-eye';
        eyeButton.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #dc3545, #b02a37);
            border-radius: 50%;
            cursor: pointer;
            z-index: 999998;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
            transition: all 0.3s ease;
            border: 3px solid #fff;
        `;
        

        eyeButton.addEventListener('click', showSuspiciousImagesModal);
        eyeButton.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
            this.style.boxShadow = '0 6px 20px rgba(220, 53, 69, 0.6)';
        });
        eyeButton.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = '0 4px 15px rgba(220, 53, 69, 0.4)';
        });
        
        document.body.appendChild(eyeButton);
    }

    function showEyeButton() {
        if (eyeButton) eyeButton.style.display = 'flex';
    }

    function hideEyeButton() {
        if (eyeButton) eyeButton.style.display = 'none';
    }

    function createModal() {
        if (modalElement) return;
       
        modalElement = document.createElement('div');
        modalElement.id = 'suspicious-images-modal';
        modalElement.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 999999;
            display: none;
            overflow-y: auto;
            padding: 20px;
        `;
       
        modalElement.innerHTML = `
            <div style="
                background: white;
                border-radius: 10px;
                max-width: 900px;
                margin: 0 auto;
                padding: 20px;
                position: relative;
            ">
                <div style="
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #eee;
                    padding-bottom: 10px;
                ">
                    <h2 style="margin: 0; color: #333;">Suspicious Activity Report</h2>
                    <div>
                        <button id="clear-all-images" style="
                            background: #ffc107;
                            color: #212529;
                            border: none;
                            padding: 8px 16px;
                            border-radius: 5px;
                            cursor: pointer;
                            font-size: 14px;
                            margin-right: 10px;
                        ">Clear All</button>
                        <button id="close-modal" style="
                            background: #767474;
                            color: white;
                            border: none;
                            padding: 8px 16px;
                            border-radius: 5px;
                            cursor: pointer;
                            font-size: 16px;
                        ">Close</button>
                    </div>
                </div>
                <div id="images-container" style="
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                    gap: 20px;
                    max-height: 70vh;
                    overflow-y: auto;
                "></div>
            </div>
        `;
       
        document.body.appendChild(modalElement);
        document.getElementById('close-modal').addEventListener('click', closeSuspiciousImagesModal);
        document.getElementById('clear-all-images').addEventListener('click', clearAllSuspiciousImages);
    }

    function clearAllSuspiciousImages() {
        suspiciousImages = [];
        try {
            const allImages = JSON.parse(localStorage.getItem('allSuspiciousImages') || '{}');
            if (allImages[quizId]) {
                delete allImages[quizId];
                localStorage.setItem('allSuspiciousImages', JSON.stringify(allImages));
            }
            localStorage.removeItem('suspiciousImages');
        } catch (e) {}
        showSuspiciousImagesModal();
        performMemoryCleanup();
    }

    function captureSuspiciousImage(alertType, details = '') {
        if (!videoElement || !canvasElement) return;
       
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 320;
            canvas.height = 240;
            const ctx = canvas.getContext('2d');
           
            ctx.drawImage(videoElement, 0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#dc3545';
            ctx.lineWidth = 4;
            ctx.strokeRect(0, 0, canvas.width, canvas.height);
           
            const warningText = getWarningText(alertType);
            ctx.fillStyle = '#dc3545';
            ctx.font = 'bold 16px Arial';
            ctx.fillRect(0, 0, canvas.width, 30);
            ctx.fillStyle = '#ffffff';
            ctx.fillText(warningText, 10, 20);
           
            const timestamp = new Date().toLocaleString();
            ctx.fillStyle = '#dc3545';
            ctx.fillRect(0, canvas.height - 25, canvas.width, 25);
            ctx.fillStyle = '#ffffff';
            ctx.font = '12px Arial';
            ctx.fillText(timestamp, 10, canvas.height - 8);
           
            const imageData = canvas.toDataURL('image/png');
           
            const suspiciousImage = {
                id: ++imageCounter,
                type: alertType,
                image: imageData,
                timestamp: timestamp,
                details: details,
                quizId: quizId,
                uploaded: false,
                uploadFailed: false,
                serverUrl: null,
                serverFilename: null
            };
           
            suspiciousImages.push(suspiciousImage);
           
            try {
                const allImages = JSON.parse(localStorage.getItem('allSuspiciousImages') || '{}');
                if (!allImages[quizId]) allImages[quizId] = [];
                allImages[quizId].push(suspiciousImage);
                localStorage.setItem('allSuspiciousImages', JSON.stringify(allImages));
                localStorage.setItem('suspiciousImages', JSON.stringify(suspiciousImages));
            } catch (e) {}
            
            sendSuspiciousDataToServer();
            
            ctx.clearRect(0, 0, canvas.width, canvas.height);

        } catch (error) {
            console.warn('Image capture error:', error);
        }
    }

    function getWarningText(alertType) {
        const texts = {
            'movement': 'Suspicious movement detected',
            'face': 'Face not detected',
            'face_turned': 'Face turned away from camera'
        };
        return texts[alertType] || 'Warning detected';
    }

    function showSuspiciousImagesModal() {
        createModal();
        const container = document.getElementById('images-container');
        container.innerHTML = '';
       
        if (suspiciousImages.length === 0) {
            container.innerHTML = `
                <div style="
                    text-align: center;
                    padding: 40px;
                    color: #666;
                    font-size: 18px;
                    grid-column: 1 / -1;
                ">
                    No suspicious activity detected
                </div>
            `;
        } else {
            suspiciousImages.forEach(item => {
                const uploadStatus = item.uploaded ? '✅ Uploaded' : 
                                    item.uploadFailed ? '❌ Upload Failed' : 
                                    '⏳ Pending Upload';

                const statusColor = item.uploaded ? '#28a745' : 
                                   item.uploadFailed ? '#dc3545' : '#ffc107';

                const imageDiv = document.createElement('div');
                imageDiv.style.cssText = `
                    border: 2px solid #dc3545;
                    border-radius: 10px;
                    padding: 10px;
                    background: #f9f9f9;
                    text-align: center;
                `;
               
                imageDiv.innerHTML = `
                    <img src="${item.image}" style="
                        width: 100%;
                        border-radius: 5px;
                        margin-bottom: 10px;
                    " loading="lazy">
                    <div style="
                        font-weight: bold;
                        color: #dc3545;
                        margin-bottom: 5px;
                    ">${getWarningText(item.type)}</div>
                    <div style="
                        font-size: 12px;
                        color: #666;
                        margin-bottom: 5px;
                    ">${item.timestamp}</div>
                    <div style="
                        font-size: 11px;
                        color: ${statusColor};
                        margin-bottom: 10px;
                        font-weight: bold;
                    ">
                        ${uploadStatus}
                    </div>
                    <button onclick="deleteSuspiciousImage(${item.id})" style="
                        background: #dc3545;
                        color: white;
                        border: none;
                        padding: 5px 10px;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 12px;
                    ">Delete</button>
                    ${item.serverUrl ? `<br><a href="${item.serverUrl}" target="_blank" style="font-size: 11px;">View on Server</a>` : ''}
                `;
                container.appendChild(imageDiv);
            });
        }
        modalElement.style.display = 'block';
    }

    function closeSuspiciousImagesModal() {
        if (modalElement) modalElement.style.display = 'none';
    }

    window.deleteSuspiciousImage = function(id) {
        suspiciousImages = suspiciousImages.filter(img => img.id !== id);
        try {
            const allImages = JSON.parse(localStorage.getItem('allSuspiciousImages') || '{}');
            if (allImages[quizId]) {
                allImages[quizId] = allImages[quizId].filter(img => img.id !== id);
                localStorage.setItem('allSuspiciousImages', JSON.stringify(allImages));
            }
            localStorage.setItem('suspiciousImages', JSON.stringify(suspiciousImages));
        } catch (e) {}
        showSuspiciousImagesModal();
    };

    function loadSavedImages() {
        try {
            const allImages = JSON.parse(localStorage.getItem('allSuspiciousImages')) || {};
            suspiciousImages = allImages[quizId] || [];
            imageCounter = suspiciousImages.length > 0 ?
                Math.max(...suspiciousImages.map(img => img.id)) : 0;
        } catch (error) {
            suspiciousImages = [];
        }
    }

    function createWarningElement() {
        if (warningElement) return;
       
        warningElement = document.createElement('div');
        warningElement.id = 'proctoring-face-warning';
        warningElement.innerHTML = `
            <div style="
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: linear-gradient(135deg, #dc3545, #b02a37);
                color: white;
                padding: 25px;
                border-radius: 12px;
                font-size: 18px;
                font-weight: bold;
                text-align: center;
                z-index: 999999;
                box-shadow: 0 8px 32px rgba(220, 53, 69, 0.4);
                border: 2px solid #dc3545;
                min-width: 350px;
                font-family: Arial, sans-serif;
                display: none;
                animation: slideIn 0.3s ease-out;
            ">
                <div style="font-size: 24px; margin-bottom: 10px;">⚠️ WARNING</div>
                <div style="margin-bottom: 8px;" id="warning-message">Please look at the camera</div>
            </div>
        `;
       
        document.body.appendChild(warningElement);
       
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translate(-50%, -50%) scale(0.8); opacity: 0; }
                to { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            }
            @keyframes pulse {
                0%, 100% { transform: translate(-50%, -50%) scale(1); }
                50% { transform: translate(-50%, -50%) scale(1.05); }
            }
            #proctoring-face-warning > div {
                animation: pulse 2s infinite;
            }
            #proctoring-canvas,
            #proctoring-video,
            #proctoring-monitor {
                display: none;
            }
        `;
        document.head.appendChild(style);
    }

    function showWarning(alertType = 'face') {
        const currentTime = Date.now();
        if (currentTime - lastWarningTime < warningCooldown) return;
        
        lastWarningTime = currentTime;
        captureSuspiciousImage(alertType);
        playWarningSound();
        
        createWarningElement();
       
        const messageElement = document.getElementById('warning-message');
        const messages = {
            'face': 'Look directly at the camera',
            'movement': 'Avoid excessive movement',
            'face_turned': 'Please face the camera directly'
        };
       
        messageElement.textContent = messages[alertType] || messages['face'];
       
        warningElement.firstElementChild.style.display = 'block';
       
        setTimeout(() => {
            hideWarning();
        }, 3000);
    }

    function hideWarning() {
        if (warningElement) warningElement.firstElementChild.style.display = 'none';
    }

    async function initializeCamera() {
        return new Promise(async (resolve, reject) => {
            try {
                await loadFaceApiLibrary();
                await loadFaceApiModels();
                
                videoElement = document.createElement('video');
                videoElement.id = 'proctoring-video';
                videoElement.autoplay = true;
                videoElement.muted = true;
                videoElement.playsInline = true;
               
                canvasElement = document.createElement('canvas');
                canvasElement.id = 'proctoring-canvas';
                canvasElement.width = 320;
                canvasElement.height = 240;
                canvasElement.style.display = 'none';
               
                canvasContext = canvasElement.getContext('2d');
               
                const monitorCanvas = document.createElement('canvas');
                monitorCanvas.id = 'proctoring-monitor';
                monitorCanvas.width = 320;
                monitorCanvas.height = 240;
                monitorCanvas.style.display = 'none';
                document.body.appendChild(monitorCanvas);
               
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                        facingMode: 'user'
                    }
                });
                
                videoElement.srcObject = stream;
                
                videoElement.addEventListener('loadedmetadata', () => {
                    videoElement.play();
                    startFaceDetection();
                    startMemoryCleanup();
                    resolve();
                });
                
            } catch (err) {
                console.error('Camera initialization failed:', err);
                reject(err);
            }
        });
    }

    function detectFace(imageData) {
        const data = imageData.data;
        const width = imageData.width;
        const height = imageData.height;
       
        const grayData = new Uint8ClampedArray(width * height);
        for (let i = 0; i < data.length; i += 4) {
            const gray = (data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114);
            grayData[i / 4] = gray;
        }
       
        let faceRegions = [];
        const blockSize = 40;
        const stepSize = 20;
       
        for (let y = 0; y < height - blockSize; y += stepSize) {
            for (let x = 0; x < width - blockSize; x += stepSize) {
                const score = calculateAdvancedFaceScore(grayData, x, y, blockSize, width, height);
                if (score > 0.4) {
                    faceRegions.push({ x, y, score, size: blockSize });
                }
            }
        }
       
        if (faceRegions.length > 0) {
            const clusteredRegions = clusterFaceRegions(faceRegions);
            if (clusteredRegions.length > 0) {
                clusteredRegions.sort((a, b) => b.score - a.score);
                return clusteredRegions[0];
            }
        }
       
        return null;
    }

    function calculateAdvancedFaceScore(grayData, x, y, size, width, height) {
        let avgBrightness = 0;
        let pixelCount = 0;
        let edgeCount = 0;
        let varianceSum = 0;
        let eyeRegionScore = 0;
        let neckRegionScore = 0;
        
        const quarterSize = size / 4;
        const halfSize = size / 2;
        
        for (let dy = 0; dy < size; dy++) {
            for (let dx = 0; dx < size; dx++) {
                const px = x + dx;
                const py = y + dy;
               
                if (px < width && py < height) {
                    const index = py * width + px;
                    const brightness = grayData[index];
                    avgBrightness += brightness;
                    pixelCount++;
                   
                    if (px > 0 && py > 0 && px < width - 1 && py < height - 1) {
                        const sobelX = (
                            -grayData[(py - 1) * width + (px - 1)] +
                            grayData[(py - 1) * width + (px + 1)] +
                            -2 * grayData[py * width + (px - 1)] +
                            2 * grayData[py * width + (px + 1)] +
                            -grayData[(py + 1) * width + (px - 1)] +
                            grayData[(py + 1) * width + (px + 1)]
                        );
                        
                        const sobelY = (
                            -grayData[(py - 1) * width + (px - 1)] +
                            -2 * grayData[(py - 1) * width + px] +
                            -grayData[(py - 1) * width + (px + 1)] +
                            grayData[(py + 1) * width + (px - 1)] +
                            2 * grayData[(py + 1) * width + px] +
                            grayData[(py + 1) * width + (px + 1)]
                        );
                        
                        const magnitude = Math.sqrt(sobelX * sobelX + sobelY * sobelY);
                        if (magnitude > 30) {
                            edgeCount++;
                        }
                    }
                    
                    if (dy >= quarterSize && dy <= halfSize && dx >= quarterSize && dx <= 3 * quarterSize) {
                        const eyeRegionBrightness = brightness;
                        if (eyeRegionBrightness > 50 && eyeRegionBrightness < 150) {
                            eyeRegionScore += 1;
                        }
                    }
                    
                    if (dy >= 3 * quarterSize && dy <= size && dx >= quarterSize && dx <= 3 * quarterSize) {
                        const neckRegionBrightness = brightness;
                        if (neckRegionBrightness > 60 && neckRegionBrightness < 180) {
                            neckRegionScore += 1;
                        }
                    }
                }
            }
        }
       
        avgBrightness /= pixelCount;
        
        for (let dy = 0; dy < size; dy++) {
            for (let dx = 0; dx < size; dx++) {
                const px = x + dx;
                const py = y + dy;
                
                if (px < width && py < height) {
                    const index = py * width + px;
                    const brightness = grayData[index];
                    varianceSum += Math.pow(brightness - avgBrightness, 2);
                }
            }
        }
        
        const variance = varianceSum / pixelCount;
        const stdDev = Math.sqrt(variance);
        
        const brightnessScore = (avgBrightness > 80 && avgBrightness < 200) ? 1 : 0;
        const edgeScore = Math.min(edgeCount / (size * size * 0.3), 1);
        const varianceScore = Math.min(stdDev / 50, 1);
        const eyeScore = Math.min(eyeRegionScore / (quarterSize * quarterSize), 1);
        const neckScore = Math.min(neckRegionScore / (quarterSize * quarterSize), 1);
        
        const totalScore = (
            brightnessScore * 0.25 +
            edgeScore * 0.3 +
            varianceScore * 0.2 +
            eyeScore * 0.15 +
            neckScore * 0.1
        );
        
        return totalScore;
    }

    function clusterFaceRegions(regions) {
        const clustered = [];
        const clusterRadius = 60;
        
        for (let i = 0; i < regions.length; i++) {
            const region = regions[i];
            let foundCluster = false;
            
            for (let j = 0; j < clustered.length; j++) {
                const cluster = clustered[j];
                const distance = Math.sqrt(
                    Math.pow(region.x - cluster.x, 2) + 
                    Math.pow(region.y - cluster.y, 2)
                );
                
                if (distance < clusterRadius) {
                    if (region.score > cluster.score) {
                        clustered[j] = region;
                    }
                    foundCluster = true;
                    break;
                }
            }
            
            if (!foundCluster) {
                clustered.push(region);
            }
        }
        
        return clustered;
    }

    function checkMovement(currentFace) {
        if (!previousFacePosition) {
            previousFacePosition = currentFace;
            return false;
        }
       
        const deltaX = Math.abs(currentFace.x - previousFacePosition.x);
        const deltaY = Math.abs(currentFace.y - previousFacePosition.y);
        const movement = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
       
        faceHistory.push(currentFace);
        if (faceHistory.length > historySize) faceHistory.shift();
       
        if (movement > movementThreshold) {
            showWarning('movement');
            
            if (faceHistory.length >= 2) {
                const avgX = faceHistory.reduce((sum, f) => sum + f.x, 0) / faceHistory.length;
                const avgY = faceHistory.reduce((sum, f) => sum + f.y, 0) / faceHistory.length;
                previousFacePosition = { x: avgX, y: avgY };
            } else {
                previousFacePosition = currentFace;
            }
            return true;
        }
       
        if (faceHistory.length >= 2) {
            const avgX = faceHistory.reduce((sum, f) => sum + f.x, 0) / faceHistory.length;
            const avgY = faceHistory.reduce((sum, f) => sum + f.y, 0) / faceHistory.length;
            previousFacePosition = { x: avgX, y: avgY };
        }
       
        return false;
    }

    function startFaceDetection() {
        if (!faceDetectionActive) {
            faceDetectionActive = true;
            detectFaceLoop();
        }
    }

    async function detectFaceLoop() {
        if (!faceDetectionActive || !videoElement) return;
       
        const currentTime = Date.now();
        if (currentTime - lastDetectionTime < detectionCooldown) {
            requestAnimationFrame(detectFaceLoop);
            return;
        }
        lastDetectionTime = currentTime;
       
        try {
            const face = await detectFaceWithApi(videoElement);
           
            const monitorCanvas = document.getElementById('proctoring-monitor');
            if (monitorCanvas) {
                const monitorCtx = monitorCanvas.getContext('2d');
                monitorCtx.drawImage(videoElement, 0, 0, 320, 240);
               
                if (face) {
                    noFaceDetectedCount = 0;
                    faceTurnedAwayCount = 0;
                    
                    if (face.faceApiDetection && face.orientation && face.orientation.turned) {
                        faceTurnedAwayCount++;
                        if (faceTurnedAwayCount >= maxFaceTurnedCount) {
                            showWarning('face_turned');
                            faceTurnedAwayCount = 0;
                        }
                    } else {
                        checkMovement(face);
                    }
                    
                    if (face.width && face.height) {
                        monitorCtx.strokeStyle = '#00ff00';
                        monitorCtx.lineWidth = 2;
                        monitorCtx.strokeRect(
                            face.x * (320 / videoElement.videoWidth), 
                            face.y * (240 / videoElement.videoHeight),
                            face.width * (320 / videoElement.videoWidth), 
                            face.height * (240 / videoElement.videoHeight)
                        );
                    }
                } else {
                    noFaceDetectedCount++;
                    if (noFaceDetectedCount >= maxNoFaceCount) {
                        showWarning('face');
                    }
                }
            }
            
            detectionFrameBuffer.push(Date.now());
            if (detectionFrameBuffer.length > MAX_FRAME_BUFFER) {
                detectionFrameBuffer.shift();
            }
            
            if (currentTime - lastMemoryCleanup > MEMORY_CLEANUP_INTERVAL) {
                performMemoryCleanup();
            }
            
        } catch (error) {
            console.warn('Face detection error:', error);
        }
       
        requestAnimationFrame(detectFaceLoop);
    }

    function stopFaceDetection() {
        faceDetectionActive = false;
        stopMemoryCleanup();
        
        if (videoElement && videoElement.srcObject) {
            const tracks = videoElement.srcObject.getTracks();
            tracks.forEach(track => {
                track.stop();
                track = null;
            });
            videoElement.srcObject = null;
        }
        
        if (canvasContext) {
            canvasContext.clearRect(0, 0, canvasElement.width, canvasElement.height);
        }
        
        performMemoryCleanup();
    }

    function checkQuizActive() {
        return window.location.href.includes('/mod/quiz/attempt.php') ||
               document.querySelector('.que') !== null ||
               document.querySelector('#quiz-container') !== null;
    }

    function checkReportPage() {
        return window.location.href.includes('proctoring_report.php') ||
               document.querySelector('a[href*="proctoring_report"]') !== null;
    }

    function checkQuizFinished() {
        return document.querySelector('.quiz-summary') !== null ||
               document.querySelector('.quiz-finish-message') !== null ||
               window.location.href.includes('review.php') ||
               document.body.innerText.includes('You have completed the quiz');
    }

    function getCmidFromPage() {
        const urlParams = new URLSearchParams(window.location.search);
        const cmidFromUrl = urlParams.get('cmid');
        if (cmidFromUrl) return parseInt(cmidFromUrl);
        
        const attemptMatch = window.location.href.match(/attempt\.php\?attempt=(\d+)/);
        if (attemptMatch) return parseInt(attemptMatch[1]);
        
        const cmidInput = document.querySelector('input[name="cmid"]');
        if (cmidInput) return parseInt(cmidInput.value);
        
        const hiddenCmid = document.querySelector('input[type="hidden"][name="cmid"]');
        if (hiddenCmid) return parseInt(hiddenCmid.value);
        
        return parseInt(quizId) || 123;
    }

    function getReportIdFromPage() {
        const urlParams = new URLSearchParams(window.location.search);
        const reportId = urlParams.get('reportid');
        if (reportId) return parseInt(reportId);
        
        const attemptMatch = window.location.href.match(/attempt=(\d+)/);
        if (attemptMatch) {
            return parseInt(attemptMatch[1]) * 1000 + Math.floor(Date.now() / 1000) % 1000;
        }
        
        return Math.floor(Date.now() / 1000);
    }

    function updateLocalStorage() {
        try {
            const allImages = JSON.parse(localStorage.getItem('allSuspiciousImages') || '{}');
            if (!allImages[quizId]) allImages[quizId] = [];
            allImages[quizId] = suspiciousImages;
            localStorage.setItem('allSuspiciousImages', JSON.stringify(allImages));
            localStorage.setItem('suspiciousImages', JSON.stringify(suspiciousImages));
        } catch (e) {
            console.warn('LocalStorage update failed:', e);
        }
    }

    function sendImageWithRetry(payload, item, index, maxRetries) {
        fetch(M.cfg.wwwroot + '/mod/quiz/accessrule/proctoring/uploadwarning.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                item.uploaded = true;
                item.serverUrl = data.url;
                item.serverFilename = data.filename;
                updateLocalStorage();
            } else {
                throw new Error(data.error || 'Unknown server error');
            }
        })
        .catch(err => {
            if (maxRetries > 0) {
                setTimeout(() => {
                    sendImageWithRetry(payload, item, index, maxRetries - 1);
                }, 2000);
            } else {
                item.uploadFailed = true;
                updateLocalStorage();
            }
        });
    }

    function sendSuspiciousDataToServer() {
        if (!suspiciousImages.length) return;

        const cmid = getCmidFromPage();
        const reportid = getReportIdFromPage();

        const imagesToSend = suspiciousImages.filter(item => !item.uploaded && !item.uploadFailed);

        imagesToSend.forEach((item, index) => {
            const payload = {
                cmid: cmid,
                reportid: reportid,
                type: item.type || 'unknown',
                image: item.image,
            };

            sendImageWithRetry(payload, item, index, 3);
        });
    }

    function monitorPageChanges() {
        let lastUrl = window.location.href;
       
        setInterval(() => {
            const currentUrl = window.location.href;
            if (currentUrl !== lastUrl) {
                lastUrl = currentUrl;
               
                if (checkReportPage() || checkQuizFinished()) {
                    quizFinished = true;
                    stopFaceDetection(); 
                    loadSavedImages();
                } else if (checkQuizActive()) {
                    quizFinished = false;
                    hideEyeButton();
                }
            }
           
            const reportButtons = document.querySelectorAll('a[href*="proctoring_report"], button.proctoring-report');
            reportButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(() => {
                        if (checkReportPage()) {
                            loadSavedImages();
                        }
                    }, 500);
                });
            });
           
            if (!quizFinished && checkQuizFinished()) {
                quizFinished = true;
                stopFaceDetection(); 
                loadSavedImages();
            }
        }, 1000);
    }

    async function initializeMonitoring() {
        quizId = getQuizId();
       
        if (checkReportPage() || checkQuizFinished()) {
            quizFinished = true;
            loadSavedImages();
            return;
        }

        if (!checkQuizActive()) return;

        isQuizActive = true;
        hideEyeButton();
        loadSavedImages();

        const observer = new MutationObserver(function() {
            const warningBtn = document.querySelector('.show-suspicious-images');
            if (warningBtn) {
                warningBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    showSuspiciousImagesModal();
                });
            }
           
            const reportButtons = document.querySelectorAll('a[href*="proctoring_report"], button.proctoring-report');
            reportButtons.forEach(button => {
                button.addEventListener('click', function() {
                    setTimeout(() => {
                        if (checkReportPage()) {
                            loadSavedImages();
                        }
                    }, 500);
                });
            });
           
            if (!quizFinished && checkQuizFinished()) {
                quizFinished = true;
                stopFaceDetection(); 
                loadSavedImages();
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });

        setTimeout(async () => {
            try {
                await initializeCamera();
                console.log('Face detection initialized with Face-API.js support:', faceApiLoaded && faceApiModels);
            } catch (err) {
                console.error('Camera initialization failed:', err);
            }
        }, 6000);

        lastActivity = Date.now();
    }

    function cleanup() {
        stopFaceDetection();
        stopMemoryCleanup();
        
        if (memoryCleanupInterval) {
            clearInterval(memoryCleanupInterval);
        }
        
        window.removeEventListener('beforeunload', cleanup);
        window.removeEventListener('unload', cleanup);
        
        detectionFrameBuffer = [];
        faceHistory = [];
        suspiciousActivityLog = [];
        
        console.log('Face detection cleanup completed');
    }

    function getCurrentStudentInfo() {
    const studentName = document.querySelector('.usertext')?.textContent?.trim() ||
                       document.querySelector('#page-header .page-header-headings h1')?.textContent?.trim() ||
                       'Unknown Student';
    
    const studentEmail = window.USER?.email || 'unknown@email.com';
    const studentId = window.USER?.id || null;
    
    return {
        name: studentName,
        email: studentEmail,
        id: studentId
    };
}

function getQuizName() {
    const quizTitle = document.querySelector('h1.h2')?.textContent?.trim() ||
                     document.querySelector('.quiz-name')?.textContent?.trim() ||
                     document.querySelector('h1')?.textContent?.trim() ||
                     'Quiz';
    
    return quizTitle;
}
function sendNotificationToServer(warningType) {
    const studentInfo = getCurrentStudentInfo();
    const quizName = getQuizName();
    const cmid = getCmidFromPage();
    
    const payload = {
        cmid: cmid,
        type: warningType,
        studentid: studentInfo.id,
        studentname: studentInfo.name,
        studentemail: studentInfo.email,
        quizname: quizName,
        timestamp: new Date().toISOString(),
        userAgent: navigator.userAgent
    };
    
    // إرسال إلى message.php
    fetch(M.cfg.wwwroot + '/mod/quiz/accessrule/proctoring/message.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            console.log(`Notification sent: ${warningType} - ${data.sent_count} recipients`);
        } else {
            console.warn('Notification failed:', data.error);
        }
    })
    .catch(error => {
        console.error('Notification error:', error);
    });
}

// تعديل دالة showWarning لإضافة إرسال التنبيه
function showWarning(alertType = 'face') {
    const currentTime = Date.now();
    if (currentTime - lastWarningTime < warningCooldown) return;
    
    lastWarningTime = currentTime;
    
    // التقاط الصورة كما هو
    captureSuspiciousImage(alertType);
    
    // تشغيل الصوت
    playWarningSound();
    
    // إرسال التنبيه للسيرفر (الجديد)
    sendNotificationToServer(alertType);
    
    // باقي كود إظهار التحذير
    createWarningElement();
    
    const messageElement = document.getElementById('warning-message');
    const messages = {
        'face': 'Look directly at the camera',
        'movement': 'Avoid excessive movement',
        'face_turned': 'Please face the camera directly'
    };
    
    messageElement.textContent = messages[alertType] || messages['face'];
    warningElement.firstElementChild.style.display = 'block';
    
    setTimeout(() => {
        hideWarning();
    }, 3000);
}

// دالة للتحقق من إعدادات المستخدم (اختيارية)
function checkUserNotificationSettings() {
    // يمكن إضافة فحص لإعدادات المستخدم هنا
    // لتحديد ما إذا كان يريد استلام التنبيهات أم لا
    return true;
}

// دالة لإرسال تقرير شامل في نهاية الكويز
function sendFinalReport() {
    if (!suspiciousImages.length) return;
    
    const studentInfo = getCurrentStudentInfo();
    const quizName = getQuizName();
    const cmid = getCmidFromPage();
    
    const reportData = {
        cmid: cmid,
        studentid: studentInfo.id,
        studentname: studentInfo.name,
        studentemail: studentInfo.email,
        quizname: quizName,
        total_warnings: suspiciousImages.length,
        warning_types: [...new Set(suspiciousImages.map(img => img.type))],
        session_duration: Date.now() - lastActivity,
        completed_at: new Date().toISOString()
    };
    
    // يمكن إرسال تقرير شامل منفصل
    console.log('Quiz session completed with suspicious activities:', reportData);
}

// إضافة استدعاء التقرير النهائي عند انتهاء الكويز
function checkQuizFinished() {
    const isFinished = document.querySelector('.quiz-summary') !== null ||
                      document.querySelector('.quiz-finish-message') !== null ||
                      window.location.href.includes('review.php') ||
                      document.body.innerText.includes('You have completed the quiz');
    
    if (isFinished && !quizFinished) {
        sendFinalReport();
    }
    
    return isFinished;
}

    window.addEventListener('beforeunload', cleanup);
    window.addEventListener('unload', cleanup);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initializeMonitoring();
            monitorPageChanges();
        });
    } else {
        initializeMonitoring();
        monitorPageChanges();
    }

})();