// expect: 0
// toolchain: true
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct _ExceptionInfo {
    int severity;
    char reason[128];
} ExceptionInfo;

typedef struct _DocumentInfo {
    char filename[256];
    unsigned int quality;
    unsigned int antialias : 1;
    unsigned int verbose : 1;
    unsigned int debug : 2;
    unsigned int depth : 8;
    struct _DocumentInfo* next;
} DocumentInfo;

typedef struct _ColorPacket {
    unsigned char red;
    unsigned char green;
    unsigned char blue;
    unsigned char alpha;
} ColorPacket;

typedef int StatusType;
#define STATUS_FALSE 0
#define STATUS_TRUE 1

static const char* SetClientName(const char* name) {
    static char client_name[256] = "";
    if (name != (const char*)0 && *name != '\0') {
        int i;
        for (i = 0; i < 255 && name[i] != '\0'; i++)
            client_name[i] = name[i];
        client_name[i] = '\0';
    }
    return *client_name == '\0' ? "Default" : client_name;
}

static DocumentInfo* CloneDocumentInfo(const DocumentInfo* src) {
    DocumentInfo* clone = (DocumentInfo*)malloc(sizeof(DocumentInfo));
    if (clone == (DocumentInfo*)0) return (DocumentInfo*)0;

    int i;
    for (i = 0; i < 256 && src->filename[i] != '\0'; i++)
        clone->filename[i] = src->filename[i];
    clone->filename[i] = '\0';
    clone->quality = src->quality;
    clone->antialias = src->antialias;
    clone->verbose = src->verbose;
    clone->debug = src->debug;
    clone->depth = src->depth;
    clone->next = (DocumentInfo*)0;
    return clone;
}

static ColorPacket InterpolateColor(ColorPacket a, ColorPacket b, unsigned int weight) {
    ColorPacket result;
    result.red = (unsigned char)((a.red * (255 - weight) + b.red * weight) / 255);
    result.green = (unsigned char)((a.green * (255 - weight) + b.green * weight) / 255);
    result.blue = (unsigned char)((a.blue * (255 - weight) + b.blue * weight) / 255);
    result.alpha = 0;
    return result;
}

int main(void) {
    // Test static local
    const char* name = SetClientName("TestApp");
    printf("client=%s\n", name);

    // Test designated initializers
    DocumentInfo info = {.quality = 85, .depth = 8};
    info.antialias = 1;
    info.verbose = 0;
    info.debug = 2;
    int i;
    for (i = 0; i < 255 && "input.dat"[i] != '\0'; i++)
        info.filename[i] = "input.dat"[i];
    info.filename[i] = '\0';
    info.next = (DocumentInfo*)0;

    printf("file=%s q=%d aa=%d v=%d dbg=%d d=%d\n",
           info.filename, info.quality, info.antialias,
           info.verbose, info.debug, info.depth);

    // Test clone
    DocumentInfo* clone = CloneDocumentInfo(&info);
    printf("clone=%s q=%d\n", clone->filename, clone->quality);

    // Test unsigned char interpolation
    ColorPacket white = {.red = 255, .green = 255, .blue = 255};
    ColorPacket black = {.red = 0, .green = 0, .blue = 0};
    ColorPacket mid = InterpolateColor(white, black, 128);
    printf("mid=%d,%d,%d\n", (int)mid.red, (int)mid.green, (int)mid.blue);

    // Test unsigned comparison with large values
    unsigned int big = 3000000000u;
    unsigned int small = 100;
    printf("cmp=%d\n", big > small ? 1 : 0);

    // Test static persistence
    SetClientName("Changed");
    printf("client2=%s\n", SetClientName((const char*)0));

    free(clone);
    return 0;
}
