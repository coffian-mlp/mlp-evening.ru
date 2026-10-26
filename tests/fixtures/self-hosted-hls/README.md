# Synthetic HLS fixture

Eight seconds of FFmpeg testsrc2 (320×180, H.264, 24 fps) and a generated 440 Hz sine tone (AAC), in separate fMP4 video and audio renditions as emitted by MediaMTX. No user content. Committed so browser playback tests do not require FFmpeg or an external media server.

Generated with FFmpeg using `testsrc2=size=320x180:rate=24`, `sine=frequency=440:sample_rate=44100`, `-t 8 -c:v libx264 -preset ultrafast -crf 32 -pix_fmt yuv420p -g 48 -sc_threshold 0 -c:a aac -b:a 64k -f hls -hls_segment_type fmp4 -hls_time 2 -hls_playlist_type vod`, separately for video and audio, with a master playlist declaring the audio group.
