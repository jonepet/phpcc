// expect: 6
// toolchain: true
extern "C" {
    void* malloc(unsigned long size);
    void free(void* ptr);
}

struct Node {
    int value;
    struct Node* next;
};

int sum_list(struct Node* head) {
    int total = 0;
    struct Node* curr = head;
    while (curr != 0) {
        total = total + curr->value;
        curr = curr->next;
    }
    return total;
}

int main() {
    struct Node* a = (struct Node*)malloc(sizeof(struct Node));
    struct Node* b = (struct Node*)malloc(sizeof(struct Node));
    struct Node* c = (struct Node*)malloc(sizeof(struct Node));
    a->value = 1;
    a->next = b;
    b->value = 2;
    b->next = c;
    c->value = 3;
    c->next = 0;
    int result = sum_list(a);
    free(a);
    free(b);
    free(c);
    return result;
}
