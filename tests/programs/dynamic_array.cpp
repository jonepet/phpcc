// expect: 0
// toolchain: true
extern "C" {
    int printf(const char* fmt, ...);
    void* malloc(unsigned long size);
    void free(void* ptr);
}

struct IntArray {
    int* data;
    int size;
    int capacity;
};

struct IntArray* array_new() {
    struct IntArray* arr = (struct IntArray*)malloc(24);
    arr->size = 0;
    arr->capacity = 4;
    arr->data = (int*)malloc(16);
    return arr;
}

void array_push(struct IntArray* arr, int value) {
    if (arr->size >= arr->capacity) {
        int newCap = arr->capacity * 2;
        int* newData = (int*)malloc(newCap * 4);
        int i = 0;
        while (i < arr->size) {
            newData[i] = arr->data[i];
            i++;
        }
        free(arr->data);
        arr->data = newData;
        arr->capacity = newCap;
    }
    arr->data[arr->size] = value;
    arr->size++;
}

int array_get(struct IntArray* arr, int index) {
    return arr->data[index];
}

void array_free(struct IntArray* arr) {
    free(arr->data);
    free(arr);
}

int main() {
    struct IntArray* arr = array_new();
    int i = 0;
    while (i < 10) {
        array_push(arr, i * i);
        i++;
    }
    printf("size=%d\n", arr->size);
    printf("arr[0]=%d arr[9]=%d\n", array_get(arr, 0), array_get(arr, 9));
    array_free(arr);
    return 0;
}
