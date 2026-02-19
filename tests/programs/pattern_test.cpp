// expect: 0
// toolchain: true
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* =========================================================
 * Pattern test — exercises common C coding patterns:
 * nested structs, void*, casts, bit fields, inline helpers,
 * large switch, error codes, string ops, function pointers,
 * multi-level indirection, struct initialization.
 * ========================================================= */

typedef int StatusType;
#define STATUS_FALSE 0
#define STATUS_TRUE  1

/* ---- 1. Nested structs ---- */
typedef struct _PointInfo {
    double x;
    double y;
} PointInfo;

typedef struct _RectangleInfo {
    long width;
    long height;
    PointInfo origin;
} RectangleInfo;

typedef struct _ColorPacket {
    unsigned char red;
    unsigned char green;
    unsigned char blue;
    unsigned char alpha;
} ColorPacket;

typedef struct _DocumentInfo {
    char filename[64];
    RectangleInfo geometry;
    ColorPacket background;
    struct _DocumentInfo* next;
    int quality;
    int flags;
} DocumentInfo;

/* ---- 2. Bit fields ---- */
typedef struct _OptionFlags {
    unsigned int adjoin    : 1;
    unsigned int antialias : 1;
    unsigned int debug     : 2;
    unsigned int depth     : 4;
} OptionFlags;

/* ---- 4. Macro-like inline helpers ---- */
static inline int MaxVal(int a, int b) { return a > b ? a : b; }
static inline int MinVal(int a, int b) { return a < b ? a : b; }
static inline int AbsVal(int v)        { return v < 0 ? -v : v; }

static inline ColorPacket ClampColor(ColorPacket p) {
    if (p.red   > 255) p.red   = 255;
    if (p.green > 255) p.green = 255;
    if (p.blue  > 255) p.blue  = 255;
    return p;
}

/* ---- 3. void* and casting ---- */
void* AllocateMemory(unsigned long size) {
    return malloc(size);
}

void FreeMemory(void* ptr) {
    free(ptr);
}

int FillBuffer(void* buf, int count) {
    int* ibuf = (int*)buf;
    int sum = 0;
    int i;
    for (i = 0; i < count; i++) {
        ibuf[i] = i * 3;
        sum += ibuf[i];
    }
    return sum;
}

/* ---- 5. Large switch statement ---- */
typedef enum {
    UndefinedFilter = 0,
    PointFilter,
    BoxFilter,
    TriangleFilter,
    HermiteFilter,
    HanningFilter,
    HammingFilter,
    BlackmanFilter,
    GaussianFilter,
    QuadraticFilter,
    CubicFilter,
    CatromFilter,
    MitchellFilter,
    LanczosFilter,
    BesselFilter,
    SincFilter
} FilterType;

static const char* FilterName(FilterType ft) {
    switch (ft) {
        case UndefinedFilter:  return "Undefined";
        case PointFilter:      return "Point";
        case BoxFilter:        return "Box";
        case TriangleFilter:   return "Triangle";
        case HermiteFilter:    return "Hermite";
        case HanningFilter:    return "Hanning";
        case HammingFilter:    return "Hamming";
        case BlackmanFilter:   return "Blackman";
        case GaussianFilter:   return "Gaussian";
        case QuadraticFilter:  return "Quadratic";
        case CubicFilter:      return "Cubic";
        case CatromFilter:     return "Catrom";
        case MitchellFilter:   return "Mitchell";
        case LanczosFilter:    return "Lanczos";
        case BesselFilter:     return "Bessel";
        case SincFilter:       return "Sinc";
        default:               return "Unknown";
    }
}

/* ---- 7. String manipulation with pointer arithmetic ---- */
int StringLength(const char* s) {
    const char* p = s;
    while (*p != '\0') p++;
    return (int)(p - s);
}

void StringCopyN(char* dst, const char* src, int n) {
    int i;
    for (i = 0; i < n && src[i] != '\0'; i++) {
        dst[i] = src[i];
    }
    dst[i] = '\0';
}

int CountChar(const char* s, char c) {
    int count = 0;
    while (*s) {
        if (*s == c) count++;
        s++;
    }
    return count;
}

/* ---- 8. Array of function pointers ---- */
typedef int (*TransformFn)(int);

static int DoubleIt(int v)   { return v * 2; }
static int HalveIt(int v)    { return v / 2; }
static int NegateIt(int v)   { return -v; }
static int IncrementIt(int v){ return v + 1; }

static TransformFn gTransforms[4];

static void InitTransforms(void) {
    gTransforms[0] = DoubleIt;
    gTransforms[1] = HalveIt;
    gTransforms[2] = NegateIt;
    gTransforms[3] = IncrementIt;
}

static int ApplyTransforms(int value) {
    int i;
    for (i = 0; i < 4; i++) {
        value = gTransforms[i](value);
    }
    return value;
}

/* ---- 9. Multiple levels of pointer indirection ---- */
static void DoubleIndirect(int** pp) {
    **pp = **pp * 2;
}

static char** BuildStringTable(int count) {
    char** table = (char**)malloc(count * sizeof(char*));
    int i;
    for (i = 0; i < count; i++) {
        table[i] = (char*)malloc(16);
        table[i][0] = 'A' + i;
        table[i][1] = '\0';
    }
    return table;
}

static void FreeStringTable(char** table, int count) {
    int i;
    for (i = 0; i < count; i++) free(table[i]);
    free(table);
}

static void TripleIndirect(int*** ppp, int val) {
    ***ppp = val;
}

/* ---- 10. Struct initialization (nested) ---- */
static DocumentInfo* CreateDocumentInfo(const char* filename) {
    DocumentInfo* info = (DocumentInfo*)malloc(sizeof(DocumentInfo));
    if (!info) return (DocumentInfo*)0;

    StringCopyN(info->filename, filename, 63);
    info->geometry.width  = 640;
    info->geometry.height = 480;
    info->geometry.origin.x = 0.0;
    info->geometry.origin.y = 0.0;
    info->background.red   = 255;
    info->background.green = 255;
    info->background.blue  = 255;
    info->background.alpha = 0;
    info->next    = (DocumentInfo*)0;
    info->quality = 85;
    info->flags   = 0;
    return info;
}

/* ---- 11. Conditional debug pattern ---- */
static int gDebugMode = 0;

static void DebugLog(const char* msg) {
    if (gDebugMode) {
        printf("[DEBUG] %s\n", msg);
    }
}

/* ---- 12. Comma-separated declarations with mixed pointer depths ---- */
static int gBaseVal = 10;

static void MixedDecls(void) {
    int a = 1, b = 2, c = 3;
    int *pa = &a, *pb = &b;
    int **ppa = &pa, **ppb = &pb;
    int x = 0, *px = &x;

    *pa = 10;
    *pb = 20;
    **ppa += 5;
    **ppb += 5;
    *px = a + b;

    printf("mixed_decls: a=%d b=%d x=%d\n", a, b, x);
    (void)c;
    (void)ppb;
    (void)ppa;
}

/* ---- Error-handling pattern (return codes + goto cleanup) ---- */
StatusType ProcessDocument(const char* filename) {
    DocumentInfo* info = CreateDocumentInfo(filename);
    void* buffer = (void*)0;
    StatusType status = STATUS_FALSE;

    if (!info) goto cleanup;

    buffer = AllocateMemory(256 * sizeof(int));
    if (!buffer) goto cleanup;

    {
        int sum = FillBuffer(buffer, 256);
        DebugLog("FillBuffer done");
        if (sum <= 0) goto cleanup;
    }

    status = STATUS_TRUE;

cleanup:
    if (buffer) FreeMemory(buffer);
    if (info)   FreeMemory(info);
    return status;
}

int main(void) {
    /* 1. Nested struct access */
    DocumentInfo* doc = CreateDocumentInfo("test.dat");
    printf("filename=%s w=%ld h=%ld\n",
           doc->filename,
           doc->geometry.width,
           doc->geometry.height);
    printf("origin=%.1f,%.1f\n",
           doc->geometry.origin.x,
           doc->geometry.origin.y);
    printf("bg=%d,%d,%d\n",
           (int)doc->background.red,
           (int)doc->background.green,
           (int)doc->background.blue);
    FreeMemory(doc);

    /* 2. Bit fields */
    OptionFlags flags;
    flags.adjoin    = 1;
    flags.antialias = 0;
    flags.debug     = 2;
    flags.depth     = 8;
    printf("bitfield: adjoin=%d antialias=%d debug=%d depth=%d\n",
           flags.adjoin, flags.antialias, flags.debug, flags.depth);

    /* 3. void* cast and buffer fill */
    void* buf = AllocateMemory(8 * sizeof(int));
    int s = FillBuffer(buf, 8);
    printf("fill_sum=%d\n", s);
    FreeMemory(buf);

    /* 4. Inline helpers */
    printf("max=%d min=%d abs=%d\n",
           MaxVal(3, 7), MinVal(3, 7), AbsVal(-42));
    ColorPacket px;
    px.red = 200; px.green = 100; px.blue = 50; px.alpha = 0;
    ColorPacket clamped = ClampColor(px);
    printf("clamped=%d,%d,%d\n",
           (int)clamped.red, (int)clamped.green, (int)clamped.blue);

    /* 5. Large switch */
    printf("filter=%s\n", FilterName(GaussianFilter));
    printf("filter=%s\n", FilterName(LanczosFilter));
    printf("filter=%s\n", FilterName(SincFilter));

    /* 6. Error handling */
    StatusType ok = ProcessDocument("sample.dat");
    printf("process=%d\n", ok);

    /* 7. String pointer arithmetic */
    const char* path = "/usr/share/resources/patterns.dat";
    printf("len=%d slashes=%d\n",
           StringLength(path), CountChar(path, '/'));

    /* 8. Array of function pointers */
    InitTransforms();
    int transformed = ApplyTransforms(10);
    printf("transformed=%d\n", transformed);

    /* 9a. Double indirection */
    int val = 7;
    int* pval = &val;
    DoubleIndirect(&pval);
    printf("dbl_indirect=%d\n", val);

    /* 9b. String table (char**) */
    char** tbl = BuildStringTable(5);
    printf("tbl=%s%s%s%s%s\n",
           tbl[0], tbl[1], tbl[2], tbl[3], tbl[4]);
    FreeStringTable(tbl, 5);

    /* 9c. Triple indirection */
    int tval = 0;
    int* ptval = &tval;
    int** pptval = &ptval;
    TripleIndirect(&pptval, 99);
    printf("triple=%d\n", tval);

    /* 11. Conditional debug (off by default) */
    gDebugMode = 0;
    DebugLog("this should not print");
    printf("debug_ok=1\n");

    /* 12. Mixed declarations */
    MixedDecls();

    printf("base=%d\n", gBaseVal);

    return 0;
}
