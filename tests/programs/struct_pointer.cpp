// expect: 30
// toolchain: true
extern "C" {
    void* malloc(unsigned long size);
    void free(void* ptr);
}

struct Rect {
    int width;
    int height;
};

int main() {
    struct Rect* r = (struct Rect*)malloc(sizeof(struct Rect));
    r->width = 5;
    r->height = 6;
    int area = r->width * r->height;
    free(r);
    return area;
}
